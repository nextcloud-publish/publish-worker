<?php

declare(strict_types=1);

namespace App\Job;

use SsgLab\SiteBuilder;

/**
 * Renders an extracted Collectives export (already unpacked into $contentDir)
 * into a static site under $outputDir.
 */
final class SiteRenderer
{
    /**
     * Validates the export in $contentDir and renders it into $outputDir.
     *
     * @param string $contentDir The directory the export is unpacked in.
     * @param string $outputDir The directory to output the rendered site to.
     * @param string $siteTitle The human-readable heading, i.e. BuildJob::$title
     * @param mixed $renderOptions BuildJob::$render_options, validated by SiteBuilder.
     * @return int number of rendered pages
     * @throws \InvalidArgumentException if the export fails validation or SiteBuilder rejects $renderOptions
     * @throws \RuntimeException if rendering fails for an environmental reason
     */
    public function render(string $contentDir, string $outputDir, string $siteTitle, mixed $renderOptions = null): int
    {
        $this->validateCollectiveExport($contentDir);

        return (new SiteBuilder())->build($contentDir, $outputDir, $siteTitle, $renderOptions);
    }

    /**
     * Checks that the export in $contentDir can be rendered.
     * Throws \InvalidArgumentException rather than leaving it to SiteBuilder, which throws \RuntimeException:
     * a broken export is a permanent content problem, not one worth retrying.
     *
     * @param string $contentDir The directory the export is unpacked in.
     * @return void
     * @throws \InvalidArgumentException if the export contains no Markdown pages
     */
    private function validateCollectiveExport(string $contentDir): void
    {
        if (!$this->hasMarkdownPages($contentDir)) {
            throw new \InvalidArgumentException('Invalid Collectives export: no .md file found in the archive or any of its subdirectories.');
        }
    }

    /**
     * Checks if $contentDir contains any Markdown pages, in any subdirectory.
     *
     * @param string $contentDir The directory to check.
     * @return bool true when $contentDir contains a Markdown page, false when it does not
     */
    private function hasMarkdownPages(string $contentDir): bool
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($contentDir, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($entries as $entry) {
            \assert($entry instanceof \SplFileInfo);

            if ($entry->isFile() && strtolower($entry->getExtension()) === 'md') {
                return true;
            }
        }

        return false;
    }
}
