<?php

namespace HasanHawary\ExportBuilder\Console;

use HasanHawary\ExportBuilder\BaseExport;
use HasanHawary\ExportBuilder\ExportBuilder;
use HasanHawary\ExportBuilder\Support\ExportHelper;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use Throwable;

/**
 * Lists the column headings (and page titles) that have no translation in a supported
 * language, so an Arabic file never shows English headings and vice versa.
 *
 *   php artisan export:translations              all pages, all locales in export.locales
 *   php artisan export:translations user --locale=ar
 */
class CheckTranslations extends Command
{
    protected $signature = 'export:translations
                            {pages?* : Pages to check (default: every *Export class in export.namespace)}
                            {--locale=* : Locales to check (default: export.locales)}';

    protected $description = 'List export headings and titles that are missing a translation';

    public function handle(): int
    {
        $locales = $this->option('locale') ?: ExportBuilder::supportedLocales();
        $file    = (string) config('export.trans_file', 'export');
        $pages   = $this->argument('pages') ?: $this->discoverPages();

        if ($pages === []) {
            $this->warn('No export classes found in ' . config('export.namespace') . '.');

            return self::SUCCESS;
        }

        $rows    = [];
        $missing = 0;

        foreach ($pages as $page) {
            $class = ExportBuilder::exportClassFor((string) $page);

            if ($class === null) {
                $rows[] = [$page, '-', '<error>no export class</error>'];
                $missing++;
                continue;
            }

            try {
                $export = new $class(['page' => (string) $page]);
            } catch (Throwable $e) {
                $rows[] = [$page, '-', '<comment>skipped: ' . $e->getMessage() . '</comment>'];
                continue;
            }

            if (! $export instanceof BaseExport) {
                $rows[] = [$page, '-', '<comment>skipped: does not extend BaseExport</comment>'];
                continue;
            }

            $type = Str::plural(Str::snake(Str::replaceLast('Export', '', class_basename($class))));

            foreach ($locales as $locale) {
                $keys = array_values(array_unique(array_filter(
                    $export->headingKeys(),
                    fn (string $key) => ExportHelper::lookup(Str::snake(Str::lower($key)), $file, $locale) === null
                )));

                // English titles fall back to the class name; other languages need a translation
                // (same keys the PDF tries: {type}_title, {type}, pages.{page}).
                if ($export->translatedTitle($file, $locale) === null && ! str_starts_with($locale, 'en')) {
                    $keys[] = "{$type} (title)";
                }

                $missing += count($keys);
                $rows[]   = [$page, $locale, $keys === [] ? '<info>OK</info>' : implode(', ', $keys)];
            }
        }

        $this->table(['Page', 'Locale', 'Missing translations'], $rows);

        if ($missing > 0) {
            $this->line("Add the keys to your translation file ('{$file}', e.g. lang/{locale}/{$file}.php).");

            return self::FAILURE;
        }

        $this->info('Every heading is translated.');

        return self::SUCCESS;
    }

    /**
     * Pages for the *Export classes directly inside export.namespace (App\... → app/...).
     *
     * @return list<string>
     */
    private function discoverPages(): array
    {
        $namespace    = trim((string) (config('export.namespace') ?: ExportBuilder::DEFAULT_NAMESPACE), '\\');
        $appNamespace = trim(app()->getNamespace(), '\\');

        if (! str_starts_with($namespace . '\\', $appNamespace . '\\')) {
            return [];
        }

        $directory = app_path(str_replace('\\', DIRECTORY_SEPARATOR, Str::after($namespace, $appNamespace)));

        if (! is_dir($directory)) {
            return [];
        }

        return collect(glob($directory . DIRECTORY_SEPARATOR . '*Export.php') ?: [])
            ->map(fn (string $file) => Str::snake(Str::replaceLast('Export', '', basename($file, '.php'))))
            ->filter()
            ->sort()
            ->values()
            ->all();
    }
}
