<?php
declare(strict_types=1);

require_once __DIR__ . '/database.php';

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

function normalizeCell(mixed $value): string
{
    return preg_replace('/[\x00-\x1F\x7F]/u', ' ', (string) $value) ?? '';
}

function cellWidth(string $text): int
{
    if (function_exists('mb_strwidth')) {
        return mb_strwidth($text, 'UTF-8');
    }
    // Для кириллицы и латиницы работает также без расширения mbstring.
    return preg_match_all('/./us', $text);
}

function printTable(array $headers, array $rows): void
{
    $headers = array_map('normalizeCell', $headers);
    $rows = array_map(static fn(array $row): array => array_map('normalizeCell', $row), $rows);
    $widths = array_map('cellWidth', $headers);
    foreach ($rows as $row) {
        foreach ($row as $column => $value) {
            $widths[$column] = max($widths[$column], cellWidth($value));
        }
    }

    $border = static function (string $left, string $middle, string $right) use ($widths): string {
        return $left . implode($middle, array_map(
            static fn(int $width): string => str_repeat('─', $width + 2), $widths
        )) . $right . "\n";
    };
    $printRow = static function (array $row) use ($widths): void {
        $cells = [];
        foreach ($row as $column => $value) {
            $cells[] = ' ' . $value . str_repeat(' ', $widths[$column] - cellWidth($value)) . ' ';
        }
        fwrite(STDOUT, '│' . implode('│', $cells) . "│\n");
    };

    fwrite(STDOUT, $border('╭', '┬', '╮'));
    $printRow($headers);
    fwrite(STDOUT, $border('├', '┼', '┤'));
    foreach ($rows as $row) {
        $printRow($row);
    }
    fwrite(STDOUT, $border('╰', '┴', '╯'));
}

try {
    $database = connectDatabase();
    $masters = fetchMasters($database);
    fwrite(STDOUT, "Мастера СТО (номера и имена из базы данных):\n");
    printTable(['Номер', 'ФИО мастера'], array_map(
        static fn(array $master): array => [(string) $master['id'], masterFullName($master)],
        $masters
    ));

    while (true) {
        fwrite(STDOUT, "Введите номер мастера или нажмите Enter для всех: ");
        $input = fgets(STDIN);
        if ($input === false) {
            fwrite(STDERR, "\nВвод завершён. Для всех мастеров введите пустую строку.\n");
            exit(1);
        }
        try {
            $masterId = parseMasterSelection($input, $masters);
            break;
        } catch (InvalidArgumentException $error) {
            fwrite(STDOUT, 'Ошибка: ' . $error->getMessage() . "\n");
        }
    }

    $works = fetchCompletedWorks($database, $masterId);
    fwrite(STDOUT, "\nФактически оказанные услуги:\n");
    $rows = array_map(static fn(array $work): array => [
        (string) $work['master_id'],
        masterFullName($work),
        $work['performed_at'],
        $work['service_name'],
        formatPrice((int) $work['price_cents']),
    ], $works);
    printTable(['Номер мастера', 'ФИО мастера', 'Дата работы', 'Услуга', 'Стоимость, руб.'], $rows);
    fwrite(STDOUT, 'Выполненных услуг: ' . count($works) . "\n");
    if ($works === []) {
        fwrite(STDOUT, "По выбранному условию выполненных услуг нет.\n");
    }
} catch (Throwable $error) {
    fwrite(STDERR, 'Ошибка: ' . $error->getMessage() . "\n");
    exit(1);
}
