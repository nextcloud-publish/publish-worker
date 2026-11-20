-- The static_site table as publish creates it: a copy of the up() of
-- publish/migrations/Version20261118120000.php and Version20261121120000.php, with the
-- temporary default the second one drops left out. publish owns the schema; this copy is the
-- contract StaticSiteStatusStoreTest checks the worker's SQL against. Update it with publish.
CREATE TABLE static_site (
  id UUID NOT NULL,
  slug VARCHAR(128) NOT NULL,
  status VARCHAR(16) NOT NULL,
  build_id UUID DEFAULT NULL,
  updated_at TIMESTAMP(0) WITH TIME ZONE NOT NULL,
  PRIMARY KEY (id)
);
CREATE UNIQUE INDEX uniq_static_site_slug ON static_site (slug);
