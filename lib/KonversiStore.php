<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Pengaturan mesin konversi nilai (KKM, target rentang, predikat).
 */
final class KonversiStore
{
    public const KELOMPOK = [
        'rendah' => 'RENDAH',
        'sedang' => 'SEDANG',
        'tinggi' => 'TINGGI',
        'semua' => 'SEMUA',
    ];

    private string $jsonPath;

    public function __construct(?string $jsonPath = null)
    {
        $this->jsonPath = $jsonPath ?? (Config::dataDir() . '/konversi_nilai.json');
        $this->ensureSeeded();
    }

    public function getSettings(): array
    {
        $state = $this->read();
        return [
            'kkm' => (float) ($state['kkm'] ?? 75),
            'targets' => $state['targets'] ?? self::defaultTargets(75),
            'rules' => $this->listRules(),
        ];
    }

    /** @return list<array> */
    public function listRules(): array
    {
        $rules = $this->read()['rules'];
        usort($rules, static function ($a, $b) {
            return ((float) ($b['min'] ?? 0)) <=> ((float) ($a['min'] ?? 0));
        });
        return array_values($rules);
    }

    /**
     * @param array{kkm?:float|int|string,targets?:array} $input
     */
    public function saveSettings(array $input): array
    {
        $state = $this->read();
        if (isset($input['kkm'])) {
            $kkm = round((float) $input['kkm'], 2);
            if ($kkm < 0 || $kkm > 100) {
                throw new InvalidArgumentException('KKM harus antara 0–100.');
            }
            $state['kkm'] = $kkm;
        }
        if (isset($input['targets']) && is_array($input['targets'])) {
            $targets = $state['targets'] ?? self::defaultTargets((float) ($state['kkm'] ?? 75));
            foreach (['rendah', 'sedang', 'tinggi', 'semua'] as $k) {
                if (!isset($input['targets'][$k]) || !is_array($input['targets'][$k])) {
                    continue;
                }
                $min = round((float) ($input['targets'][$k]['min'] ?? 0), 2);
                $max = round((float) ($input['targets'][$k]['max'] ?? 0), 2);
                if ($max < $min) {
                    throw new InvalidArgumentException("Target {$k}: maksimum harus ≥ minimum.");
                }
                if ($min < 0 || $max > 100) {
                    throw new InvalidArgumentException("Target {$k}: rentang 0–100.");
                }
                $targets[$k] = ['min' => $min, 'max' => $max];
            }
            $state['targets'] = $targets;
        }
        $state['updated_at'] = date('c');
        $this->write($state);
        return $this->getSettings();
    }

    /**
     * @param array{id?:string,min?:float|int|string,max?:float|int|string,huruf?:string,predikat?:string} $input
     */
    public function saveRule(array $input): array
    {
        $state = $this->read();
        $id = trim((string) ($input['id'] ?? ''));
        $min = (float) ($input['min'] ?? 0);
        $max = (float) ($input['max'] ?? 0);
        $huruf = trim((string) ($input['huruf'] ?? ''));
        $predikat = trim((string) ($input['predikat'] ?? ''));

        if ($max < $min) {
            throw new InvalidArgumentException('Nilai maksimum harus ≥ minimum.');
        }
        if ($min < 0 || $max > 100) {
            throw new InvalidArgumentException('Rentang nilai harus antara 0–100.');
        }
        if ($huruf === '') {
            throw new InvalidArgumentException('Huruf konversi wajib diisi.');
        }
        if ($predikat === '') {
            throw new InvalidArgumentException('Predikat wajib diisi.');
        }

        if ($id === '') {
            $row = [
                'id' => bin2hex(random_bytes(6)),
                'min' => $min,
                'max' => $max,
                'huruf' => $huruf,
                'predikat' => $predikat,
                'updated_at' => date('c'),
            ];
            $state['rules'][] = $row;
            $this->write($state);
            return $row;
        }

        $found = false;
        foreach ($state['rules'] as &$r) {
            if ($r['id'] !== $id) {
                continue;
            }
            $r['min'] = $min;
            $r['max'] = $max;
            $r['huruf'] = $huruf;
            $r['predikat'] = $predikat;
            $r['updated_at'] = date('c');
            $found = true;
            $row = $r;
            break;
        }
        unset($r);
        if (!$found) {
            throw new InvalidArgumentException('Aturan konversi tidak ditemukan.');
        }
        $this->write($state);
        return $row;
    }

    /** @deprecated gunakan saveRule */
    public function save(array $input): array
    {
        return $this->saveRule($input);
    }

    public function delete(string $id): void
    {
        $id = trim($id);
        if ($id === '') {
            throw new InvalidArgumentException('ID aturan wajib.');
        }
        $state = $this->read();
        $before = count($state['rules']);
        $state['rules'] = array_values(array_filter(
            $state['rules'],
            static fn ($r) => ($r['id'] ?? '') !== $id
        ));
        if (count($state['rules']) === $before) {
            throw new InvalidArgumentException('Aturan konversi tidak ditemukan.');
        }
        $this->write($state);
    }

    /** @return list<array> alias */
    public function list(): array
    {
        return $this->listRules();
    }

    public function convertPredikat(float $score): ?array
    {
        foreach ($this->listRules() as $r) {
            $min = (float) ($r['min'] ?? 0);
            $max = (float) ($r['max'] ?? 0);
            if ($score >= $min && $score <= $max) {
                return [
                    'skor' => $score,
                    'huruf' => (string) ($r['huruf'] ?? ''),
                    'predikat' => (string) ($r['predikat'] ?? ''),
                    'rule_id' => (string) ($r['id'] ?? ''),
                ];
            }
        }
        return null;
    }

    /** @deprecated */
    public function convert(float $score): ?array
    {
        return $this->convertPredikat($score);
    }

    /** @return array{min:float,max:float} */
    public static function defaultTargets(float $kkm): array
    {
        $kkm = max(0.0, min(100.0, $kkm));
        $span = max(1.0, 100.0 - $kkm);
        $cMax = round($kkm + $span * 0.30, 2);
        $bMax = round($kkm + $span * 0.65, 2);
        return [
            'rendah' => ['min' => $kkm, 'max' => min(100.0, $cMax)],
            'sedang' => ['min' => min(100.0, $cMax + 0.01), 'max' => min(100.0, $bMax)],
            'tinggi' => ['min' => min(100.0, $bMax + 0.01), 'max' => 100.0],
            'semua' => ['min' => $kkm, 'max' => 100.0],
        ];
    }

    private function ensureSeeded(): void
    {
        $state = $this->read();
        $changed = false;
        if (!isset($state['kkm'])) {
            $state['kkm'] = 75;
            $changed = true;
        }
        if (empty($state['targets']) || !is_array($state['targets'])) {
            $state['targets'] = self::defaultTargets((float) $state['kkm']);
            $changed = true;
        }
        if ($state['rules'] === []) {
            $kkm = (float) $state['kkm'];
            $t = $state['targets'];
            $state['rules'] = [
                $this->makeRule(0, max(0, $kkm - 0.01), 'D', 'Kurang'),
                $this->makeRule($kkm, (float) $t['rendah']['max'], 'C', 'Cukup'),
                $this->makeRule((float) $t['sedang']['min'], (float) $t['sedang']['max'], 'B', 'Baik'),
                $this->makeRule((float) $t['tinggi']['min'], 100, 'A', 'Sangat Baik'),
            ];
            $changed = true;
        }
        if ($changed) {
            $this->write($state);
        }
    }

    private function makeRule(float $min, float $max, string $huruf, string $predikat): array
    {
        return [
            'id' => bin2hex(random_bytes(6)),
            'min' => $min,
            'max' => $max,
            'huruf' => $huruf,
            'predikat' => $predikat,
            'updated_at' => date('c'),
        ];
    }

    /** @return array{rules:list<array>,kkm?:float,targets?:array,updated_at?:string} */
    private function read(): array
    {
        if (!is_readable($this->jsonPath)) {
            return ['rules' => []];
        }
        $json = json_decode((string) file_get_contents($this->jsonPath), true);
        if (!is_array($json)) {
            return ['rules' => []];
        }
        $rules = [];
        foreach ($json['rules'] ?? [] as $r) {
            if (!is_array($r) || trim((string) ($r['id'] ?? '')) === '') {
                continue;
            }
            $rules[] = $r;
        }
        return [
            'rules' => $rules,
            'kkm' => isset($json['kkm']) ? (float) $json['kkm'] : null,
            'targets' => is_array($json['targets'] ?? null) ? $json['targets'] : null,
            'updated_at' => (string) ($json['updated_at'] ?? ''),
        ];
    }

    private function write(array $state): void
    {
        $dir = dirname($this->jsonPath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }
        $payload = [
            'kkm' => $state['kkm'] ?? 75,
            'targets' => $state['targets'] ?? self::defaultTargets(75),
            'rules' => array_values($state['rules'] ?? []),
            'updated_at' => $state['updated_at'] ?? date('c'),
        ];
        $ok = @file_put_contents(
            $this->jsonPath,
            json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
        );
        if ($ok === false) {
            throw new RuntimeException('Gagal menyimpan konversi nilai. Pastikan folder data/ writable.');
        }
    }
}
