<?php

namespace App\Services\InventoryImport;

use Illuminate\Validation\ValidationException;

class CsvReader
{
    public function read(string $bytes, array $options): array
    {
        if (strlen($bytes) > config('inventory_imports.max_kilobytes') * 1024 || str_contains($bytes, "\0")) {
            $this->fail('File troppo grande o contenuto binario non consentito.');
        }
        $encoding = $options['encoding'];
        if ($encoding === 'auto') {
            $encoding = mb_check_encoding($bytes, 'UTF-8') ? 'UTF-8' : 'Windows-1252';
        }
        if ($encoding === 'UTF-8-BOM') {
            $encoding = 'UTF-8';
        }
        if (! mb_check_encoding($bytes, $encoding)) {
            $this->fail('La codifica selezionata non corrisponde al file.');
        }
        $text = mb_convert_encoding($bytes, 'UTF-8', $encoding);
        $text = preg_replace('/^\xEF\xBB\xBF/', '', $text);
        if (preg_match('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', $text)) {
            $this->fail('Il file contiene caratteri di controllo non consentiti.');
        }
        $delimiter = $options['delimiter'] === 'tab' ? "\t" : $options['delimiter'];
        if ($delimiter === 'auto') {
            $counts = [];
            foreach ([';', ',', "\t", '|'] as $candidate) {
                try {
                    $sample = $this->parse($text, $candidate, $options['enclosure'], 1);
                    $counts[$candidate] = count($sample[0]['cells'] ?? []);
                } catch (ValidationException) {
                    $counts[$candidate] = 0;
                }
            }
            arsort($counts);
            $delimiter = array_key_first($counts);
        }
        if (strlen($delimiter) !== 1 || strlen($options['enclosure']) !== 1 || $delimiter === $options['enclosure'] || preg_match('/[\r\n\x00]/', $delimiter.$options['enclosure'])) {
            $this->fail('Delimitatore e carattere di testo devono essere singoli caratteri distinti, senza ritorni a capo.');
        }
        $records = $this->parse($text, $delimiter, $options['enclosure']);
        if (! $records) {
            $this->fail('Il CSV non contiene righe dati.');
        }
        $width = count($records[0]['cells']);
        $headers = $options['has_headers'] ? array_shift($records)['cells'] : array_map(fn ($i) => 'Colonna '.$i, range(1, $width));
        if (! $records) {
            $this->fail('Il CSV contiene solo intestazioni.');
        }
        if (count($records) > config('inventory_imports.max_rows')) {
            $this->fail('Il file supera il limite di '.config('inventory_imports.max_rows').' righe dati.');
        }

        return ['headers' => $headers, 'records' => $records, 'encoding' => $encoding, 'delimiter' => $delimiter, 'blank_rows' => $this->blankRows];
    }

    private int $blankRows = 0;

    /** Strict CSV state machine: preserves quoted newlines and reports physical source lines. */
    private function parse(string $text, string $delimiter, string $quote, int $sample = 0): array
    {
        $records = [];
        $cells = [];
        $cell = '';
        $state = 'start';
        $line = 1;
        $start = 1;
        $width = null;
        $this->blankRows = 0;
        $length = strlen($text);
        for ($i = 0; $i <= $length; $i++) {
            $c = $i === $length ? null : $text[$i];
            $newline = $c === "\r" || $c === "\n";
            if ($state === 'quoted') {
                if ($c === null) {
                    $this->fail("Riga $start: virgolette non chiuse.");
                }
                if ($c === $quote) {
                    if (($text[$i + 1] ?? null) === $quote) {
                        $cell .= $quote;
                        $i++;
                    } else {
                        $state = 'closed';
                    }
                } else {
                    $cell .= $c;
                    if ($c === "\n" || ($c === "\r" && ($text[$i + 1] ?? null) !== "\n")) {
                        $line++;
                    }
                }
            } elseif ($c === $delimiter || $newline || $c === null) {
                $cells[] = $cell;
                $cell = '';
                $state = 'start';
                if (count($cells) > 256) {
                    $this->fail("Riga $start: massimo 256 colonne.");
                }
                if ($newline || $c === null) {
                    if (count($cells) === 1 && $cells[0] === '') {
                        if ($c !== null) {
                            $this->blankRows++;
                        }
                    } else {
                        $width ??= count($cells);
                        if (count($cells) !== $width) {
                            $this->fail("Riga $start: numero di colonne incoerente (attese $width).");
                        }
                        $records[] = ['line' => $start, 'cells' => $cells];
                        if ($sample > 0 && count($records) >= $sample) {
                            return $records;
                        }
                        if (count($records) > config('inventory_imports.max_rows') + 1) {
                            $this->fail('Numero massimo di righe superato.');
                        }
                    }
                    $cells = [];
                    if ($c === "\r" && ($text[$i + 1] ?? null) === "\n") {
                        $i++;
                    }
                    $line++;
                    $start = $line;
                }
            } elseif ($state === 'closed' || $c === $quote && $state !== 'start') {
                $this->fail("Riga $start: virgolette o caratteri dopo la chiusura non validi.");
            } elseif ($state === 'start' && $c === $quote) {
                $state = 'quoted';
            } else {
                $cell .= $c;
                $state = 'plain';
            }
            if (strlen($cell) > 40000) {
                $this->fail("Riga $start: campo troppo lungo.");
            }
        }

        return $records;
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['file' => $message]);
    }
}
