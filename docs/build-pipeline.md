# The build pipeline

How `publish-worker` turns a `BuildJob` into a published site.

```text
q.builds ─► reset ─► download ─► extract ─► render ─► publish ─► callback
```

[BuildJobHandler](../src/Message/BuildJobHandler.php) runs the sequence and reports success.
[BuildFailureHandler](../src/Message/BuildFailureHandler.php) reports failure. There is no
result queue.

## Directories

```text
BUILDS_DIR/<build_id>/{input,output}      build scratch, 0750
PAGES_DIR/<slug>/                        the live site, served at PUBLISH_BASE_URL/<slug>/
PAGES_DIR/.staging/<build_id>/           the build before it goes live
```

[JobWorkspace](../src/Job/JobWorkspace.php) builds every path.

- Scratch is keyed on `build_id`, so concurrent builds of one site never share `output/`.
  The renderer does not clear `output/`, so a shared one would republish deleted pages.
- The two roots may be on different mounts. `rename()` fails across mounts with `EXDEV`, so
  [Filesystem::moveDir()](../src/Job/Filesystem.php) falls back to a recursive copy.
- `.staging` lives inside `PAGES_DIR`, so the final swap is a rename on one mount. nginx's
  `location ~ /\.` answers 404 for it.
- A failed build's directory is deleted. The callback error and the `Build failed` log line are
  its only record.

## Publishing

The handler calls `reset()` at the start of every attempt, `publish()` on success, and
`clear()` once the job is finished either way. `publish()` runs three steps:

1. Moves `output/` to `.staging/<build_id>/`. This is the slow step, and it does not
   touch the live site.
2. Sets the staged tree to `0755` for directories and `0644` for files, so nginx can read it.
   `output/` is `0750`, and files from the archive keep their tarball modes.
3. Deletes `PAGES_DIR/<slug>/` and renames the staged directory in its place.
   `rename()` onto a non-empty directory fails with `ENOTEMPTY`.

`publish()` refuses an empty `output/`, so a build with no pages never replaces a live site.
The slug is the directory, and publish keeps it unique across sites. The worker does not know a
site's previous slug, so a slug change on update leaves the old directory served.

Known limitation: the site answers 404 between the delete and the rename. Nothing records how
far an attempt got, so a retry rebuilds from the start.

## Retries and failures

`max_retries: 1` gives two build attempts, 15 s apart. Every delivery builds, so the retry
count is the build budget.

| Thrown | Means | Result |
| --- | --- | --- |
| `\RuntimeException` | may succeed next time: disk, network, broker | retried |
| `\InvalidArgumentException` | cannot succeed: archive with no pages, nothing rendered, invalid render options, callback rejected | the handler wraps it in `UnrecoverableMessageHandlingException`, reported on the first attempt |

Classes in `src/Job` throw only these two SPL exceptions. Messenger's exception is used only in
`BuildJobHandler`. A permanent error thrown as `\RuntimeException` uses up both attempts and
the delay before the client hears about it.

`BuildFailureHandler` subscribes to `WorkerMessageFailedEvent` instead of catching in the
handler, because some failures never reach the handler: a message requeued after
`consumer_timeout` arrives redelivered, and `RejectRedeliveredMessageMiddleware` throws before
the handler runs. The subscriber has priority 0, so it runs after
`SendFailedMessageForRetryListener` (100) has set `willRetry()`.

That requeue uses up an attempt, so a build that runs longer than `consumer_timeout` (120 s
dev, 300 s prod) is reported `failed` even if it would have succeeded.

The retry strategy has `jitter: 0`: the delay is part of the delay queue's name, so jitter
would declare a new queue per message and attempt.

The broker must declare the `delays` exchange. With `auto_setup: false`, Messenger still binds
its delay queues to it, and a missing exchange stops the consumer on its first retry. publish
declares it in `docker/dev/rabbitmq/definitions.json`.

## Status in publish's database

publish stores every site in its `static_site` table, as `pending` with the `build_id` it
enqueued. Before each callback the worker writes the outcome there. A published build sets the
status:

```sql
UPDATE static_site SET status = 'published' WHERE id = :static_site_id AND build_id = :build_id
```

A build that fails for good leaves nothing behind, so the client can POST the site again without
a conflict. `BuildFailureHandler` removes `PAGES_DIR/<slug>/`, then the entry:

```sql
DELETE FROM static_site WHERE id = :static_site_id AND build_id = :build_id
```

`src/Job/StaticSiteStatusStore.php` is the only SQL.

- The order is files, then entry, then callback. On success: publish, then status, then
  callback, so the database holds the result even when the callback fails. A retried build sets
  the same status again.
- A build can fail after publishing, when only its callback failed. Its published site is
  removed with the entry, so nothing unowned stays served.
- The `build_id` condition is a fence: a build publish has since replaced changes nothing, and
  neither does one whose site was deleted. Either is a `static_site status not saved` or
  `static_site entry not deleted` warning, not a failure; the callback is sent as before.
- A database error on success is an environment error: the build is retried. On failure it is
  logged as `Could not delete static_site entry in the database`, and the `failed` callback is
  sent anyway. The entry then stays, and the client's next POST of the site gets a `409` until
  an operator removes it.
- The worker owns no schema. The table is publish's migration; `tests/fixtures/static_site.sql`
  is the copy the tests check the SQL against.

## Callback

A `PATCH` to `callback_status_url` with one of two bodies:

```json
{ "status": "published", "data": { "publish_url": "https://sites.example.org/<slug>/" } }
{ "status": "failed",    "data": { "error_message": "Could not download ..." } }
```

- The PATCH carries `OCS-APIRequest: true`; without it Nextcloud's CSRF check rejects the
  anonymous OCS call with 412.
- Delivery is at-least-once: a crash between the PATCH and the ack sends it again. Receivers
  identify the build by `callback_status_url`, dedupe on `(callback_status_url, status)`, and
  treat a final status as final.
- A failed callback fails the build. 408, 429, 5xx and transport errors are retried, so the
  whole build runs again. Other 4xx and 3xx are permanent. If the callback still fails on the
  last attempt, the site is live but reported `failed`.
- The log gets the full error message. The client gets it redacted by
  `BuildFailureHandler::redact()`: the storage roots become `<build>` and `<published>`, other
  paths are shortened, URL credentials removed, and the message truncated to 500 characters.

## Not covered by the tests

`InMemoryTransportFactory` ignores its options, so nothing under `options:` in
`messenger.yaml` is tested: the queue, the quorum argument, the `exchange:` block and the
`delays` exchange. SIGTERM handling, `consumer_timeout`, readability across uids and a real
`EXDEV` are not tested either; `EXDEV` is approximated with `/dev/shm`.

Whether `messenger:consume builds` survives its first retry is only tested by running
publish's dev stack.

[MessengerConfigTest](../tests/Messenger/MessengerConfigTest.php) covers the retry budget and
the subscriber wiring. [BuildJobContractTest](../tests/Message/BuildJobContractTest.php) covers
the message format shared with publish.
