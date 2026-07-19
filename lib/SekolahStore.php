<?php

declare(strict_types=1);

require_once __DIR__ . '/Config.php';

/**
 * Multi-sekolah: nama, kepala, NIP, logo, tempat & tanggal cetak.
 * Disimpan di JSON (data/sekolah.json) + logo di data/logos/.
 */
final class SekolahStore
{
    /** Jumlah slot sekolah bawaan (SCH01–SCH100). */
    public const SCHOOL_SLOT_COUNT = 100;

    private string $jsonPath;
    private string $logoDir;

    /** Sekolah konteks request (dari user.sekolah_id). */
    private static ?string $contextId = null;

    public function __construct(?string $jsonPath = null, ?string $logoDir = null)
    {
        $data = Config::dataDir();
        $this->jsonPath = $jsonPath ?? ($data . '/sekolah.json');
        $this->logoDir = $logoDir ?? ($data . '/logos');
        if (!is_dir($this->logoDir)) {
            @mkdir($this->logoDir, 0777, true);
        }
    }

    /** Set sekolah yang dipakai di request (admin terikat ke sekolahnya). */
    public static function setContextId(?string $id): void
    {
        $id = trim((string) $id);
        self::$contextId = $id !== '' ? $id : null;
    }

    public static function contextId(): ?string
    {
        return self::$contextId;
    }

    /** @return array{active_id:?string,sekolah:list<array>,seeded_ten?:bool,recent_ids?:list<string>} */
    public function state(): array
    {
        $state = $this->read();
        $state = $this->ensureSchoolSlots($state);
        if ($state['sekolah'] === []) {
            $default = $this->defaultSekolah();
            $state['sekolah'][] = $default;
            $state['active_id'] = $default['id'];
            $this->write($state);
        }
        if ($state['active_id'] === null || !$this->find($state['active_id'], $state)) {
            $state['active_id'] = $state['sekolah'][0]['id'];
            $this->write($state);
        }
        // Migrasi ringan: nama bukan default "Sekolah N" → sudah dipakai
        $migrated = false;
        foreach ($state['sekolah'] as &$row) {
            if (!empty($row['dipakai'])) {
                if (trim((string) ($row['dipakai_at'] ?? '')) === '') {
                    $row['dipakai_at'] = (string) ($row['last_used_at'] ?? $row['updated_at'] ?? date('c'));
                    $migrated = true;
                }
                continue;
            }
            if ($this->isCustomNama((string) ($row['nama'] ?? ''), (string) ($row['id'] ?? ''))) {
                $row['dipakai'] = true;
                $now = (string) ($row['updated_at'] ?? date('c'));
                $row['dipakai_at'] = $now;
                if (trim((string) ($row['last_used_at'] ?? '')) === '') {
                    $row['last_used_at'] = $now;
                }
                $state['recent_ids'] = $this->pushRecent($state['recent_ids'] ?? [], (string) $row['id']);
                $state = $this->recordUsageInState($state, (string) $row['id'], (string) $row['nama']);
                $migrated = true;
            }
        }
        unset($row);
        // Backfill statistik dari sekolah yang sedang dipakai (tanpa menambah times)
        foreach ($state['sekolah'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || $id === 'SCH01') {
                continue;
            }
            if (!empty($row['dipakai']) || $this->isCustomNama((string) ($row['nama'] ?? ''), $id)) {
                $ids = array_column($state['usage_stats']['history'] ?? [], 'id');
                if (!in_array($id, $ids, true)) {
                    $state = $this->recordUsageInState($state, $id, (string) ($row['nama'] ?? ''), false);
                    $migrated = true;
                }
            }
        }
        if ($migrated) {
            try {
                $this->write($state);
            } catch (Throwable) {
            }
        }

        $state = $this->expireDipakaiSchools($state);
        return $state;
    }

    /**
     * Sekolah non-utama yang sudah dipakai > 7 hari:
     * hapus Excel/data akademik, reset nama ke "Sekolah N", admin password ke Admin123.
     * SCH01 (utama) tidak pernah di-reset otomatis.
     *
     * @param array{active_id:?string,sekolah:list<array>,recent_ids?:list<string>} $state
     * @return array{active_id:?string,sekolah:list<array>,recent_ids?:list<string>}
     */
    private function expireDipakaiSchools(array $state): array
    {
        $ttlSeconds = 7 * 24 * 60 * 60;
        $nowTs = time();
        $changed = false;
        $now = date('c');

        foreach ($state['sekolah'] as $i => $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || $id === 'SCH01') {
                continue;
            }
            $dipakai = !empty($row['dipakai'])
                || $this->isCustomNama((string) ($row['nama'] ?? ''), $id);
            if (!$dipakai) {
                continue;
            }
            $since = trim((string) ($row['dipakai_at'] ?? $row['last_used_at'] ?? $row['updated_at'] ?? ''));
            $sinceTs = $since !== '' ? strtotime($since) : false;
            if ($sinceTs === false || ($nowTs - $sinceTs) < $ttlSeconds) {
                continue;
            }

            $n = 0;
            if (preg_match('/^SCH(\d+)$/i', $id, $m)) {
                $n = (int) $m[1];
            }
            $defaultNama = $n > 0 ? ('Sekolah ' . $n) : ('Sekolah');

            // Catat ke statistik sebelum reset (nama terakhir yang dipakai)
            $state = $this->recordUsageInState(
                $state,
                $id,
                (string) ($row['nama'] ?? $defaultNama),
                false
            );

            if (!empty($row['logo_file'])) {
                $this->removeLogoFile((string) $row['logo_file']);
            }
            $this->purgeSchoolAcademicData($id);

            $created = (string) ($row['created_at'] ?? $now);
            $state['sekolah'][$i] = array_merge($this->blankSekolah(), [
                'id' => $id,
                'nama' => $defaultNama,
                'aktif' => true,
                'dipakai' => false,
                'dipakai_at' => '',
                'last_used_at' => '',
                'created_at' => $created,
                'updated_at' => $now,
            ]);

            $state['recent_ids'] = array_values(array_filter(
                $state['recent_ids'] ?? [],
                static fn ($rid) => (string) $rid !== $id
            ));
            if (($state['active_id'] ?? '') === $id) {
                $state['active_id'] = 'SCH01';
            }

            $this->resetSchoolAdminPassword($id);
            $changed = true;
        }

        if ($changed) {
            try {
                $this->write($state);
            } catch (Throwable) {
            }
        }
        return $state;
    }

    /** Hapus folder data akademik sekolah (Excel, cache, ujian, dll.). */
    private function purgeSchoolAcademicData(string $sekolahId): void
    {
        $sekolahId = Config::sanitizeSchoolId($sekolahId);
        if ($sekolahId === 'SCH01') {
            return;
        }
        $root = Config::dataDir() . '/schools/' . $sekolahId;
        if (!is_dir($root)) {
            return;
        }
        $this->deleteTree($root);
        @mkdir($root . '/semua', 0777, true);
    }

    private function deleteTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }
        $items = @scandir($dir);
        if (!is_array($items)) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->deleteTree($path);
                @rmdir($path);
            } else {
                @unlink($path);
            }
        }
    }

    private function resetSchoolAdminPassword(string $sekolahId): void
    {
        try {
            require_once __DIR__ . '/UserStore.php';
            (new UserStore())->resetAdminPasswordForSekolah($sekolahId, 'Admin123');
        } catch (Throwable) {
        }
    }

    /** @return list<array> */
    public function all(): array
    {
        return $this->state()['sekolah'];
    }

    public function active(): array
    {
        $state = $this->state();
        // Prioritas: konteks user (admin1 → SCH01, dst.)
        if (self::$contextId !== null) {
            $ctx = $this->find(self::$contextId, $state);
            if ($ctx !== null) {
                return $ctx;
            }
        }
        $aktif = $this->find($state['active_id'], $state);
        return $aktif ?? $state['sekolah'][0];
    }

    public function get(string $id): ?array
    {
        return $this->find($id, $this->state());
    }

    public function setActive(string $id): array
    {
        $state = $this->state();
        if (!$this->find($id, $state)) {
            throw new InvalidArgumentException('Sekolah tidak ditemukan.');
        }
        $state['active_id'] = $id;
        $this->write($state);
        return $this->active();
    }

    /**
     * Sekolah siap dipakai di login: belum terpakai (nama belum diedit & disimpan).
     * Sekolah utama SCH01 tidak ditampilkan. Sekolah yang sudah terpakai tetap bisa
     * dilogin manual dengan adminN / Admin123.
     *
     * @return list<array{id:string,nama:string,admin_username:string,last_used_at:string,logo_url:?string,dipakai:bool}>
     */
    public function availableForLogin(int $limit = 5): array
    {
        $state = $this->state();
        $candidates = [];
        foreach ($state['sekolah'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || $id === 'SCH01') {
                continue;
            }
            $dipakai = !empty($row['dipakai'])
                || $this->isCustomNama((string) ($row['nama'] ?? ''), $id);
            if ($dipakai) {
                continue;
            }
            $candidates[] = $row;
        }

        usort($candidates, static function (array $a, array $b): int {
            $na = 0;
            $nb = 0;
            if (preg_match('/^SCH(\d+)$/i', (string) ($a['id'] ?? ''), $m)) {
                $na = (int) $m[1];
            }
            if (preg_match('/^SCH(\d+)$/i', (string) ($b['id'] ?? ''), $m)) {
                $nb = (int) $m[1];
            }
            return $na <=> $nb;
        });

        $out = [];
        foreach ($candidates as $row) {
            if (count($out) >= $limit) {
                break;
            }
            $id = (string) ($row['id'] ?? '');
            $n = 0;
            if (preg_match('/^SCH(\d+)$/i', $id, $m)) {
                $n = (int) $m[1];
            }
            $item = $this->withLogoUrl($row);
            $out[] = [
                'id' => $id,
                'nama' => (string) ($item['nama'] ?? $id),
                'admin_username' => $n > 0 ? ('admin' . $n) : '',
                'last_used_at' => (string) ($row['last_used_at'] ?? ''),
                'logo_url' => $item['logo_url'] ?? null,
                'dipakai' => false,
            ];
        }
        return $out;
    }

    /** @deprecated Gunakan availableForLogin() — daftar login = belum terpakai. */
    public function recentForLogin(int $limit = 5): array
    {
        return $this->availableForLogin($limit);
    }

    /**
     * Statistik sekolah yang pernah memakai (selain Sekolah 1 / utama).
     * Riwayat menyimpan nama yang pernah dipakai; tidak dihapus saat slot di-reset.
     *
     * @return array{
     *   total_slots:int,pernah:int,sedang_pakai:int,siap:int,
     *   history:list<array{id:string,nama:string,first_at:string,last_at:string,times:int}>
     * }
     */
    public function usageStats(): array
    {
        $state = $this->state();
        $history = [];
        foreach (($state['usage_stats']['history'] ?? []) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['id'] ?? '');
            $nama = trim((string) ($row['nama'] ?? ''));
            if ($id === '' || $id === 'SCH01' || $nama === '') {
                continue;
            }
            // Lewati sisa entri default jika ada
            if (!$this->isCustomNama($nama, $id)) {
                continue;
            }
            $history[] = [
                'id' => $id,
                'nama' => $nama,
                'first_at' => (string) ($row['first_at'] ?? ''),
                'last_at' => (string) ($row['last_at'] ?? ''),
                'times' => max(1, (int) ($row['times'] ?? 1)),
            ];
        }
        usort($history, static fn ($a, $b) => strcmp((string) $b['last_at'], (string) $a['last_at']));

        $sedang = 0;
        $siap = 0;
        foreach ($state['sekolah'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id === '' || $id === 'SCH01') {
                continue;
            }
            $dipakai = !empty($row['dipakai'])
                || $this->isCustomNama((string) ($row['nama'] ?? ''), $id);
            if ($dipakai) {
                $sedang++;
            } else {
                $siap++;
            }
        }

        return [
            'total_slots' => max(0, count($state['sekolah']) - 1),
            'pernah' => count($history),
            'sedang_pakai' => $sedang,
            'siap' => $siap,
            'history' => $history,
        ];
    }

    /**
     * Catat / perbarui riwayat pemakaian. Nama custom tidak diganti dengan nama default
     * saat slot di-reset; pemakaian ulang dengan nama lain menambah entri baru.
     *
     * @param array $state
     * @return array
     */
    private function recordUsageInState(array $state, string $id, string $nama, bool $incrementTimes = true): array
    {
        $id = trim($id);
        if ($id === '' || $id === 'SCH01') {
            return $state;
        }
        $nama = trim($nama);
        $now = date('c');
        $history = [];
        foreach (($state['usage_stats']['history'] ?? []) as $row) {
            if (!is_array($row) || trim((string) ($row['id'] ?? '')) === '') {
                continue;
            }
            // Bersihkan entri lama yang hanya berisi nama default
            $rowNama = trim((string) ($row['nama'] ?? ''));
            $rowId = (string) ($row['id'] ?? '');
            if ($rowNama === '' || !$this->isCustomNama($rowNama, $rowId)) {
                continue;
            }
            $history[] = $row;
        }

        $isCustom = $nama !== '' && $this->isCustomNama($nama, $id);

        // Nama default (setelah reset) tidak boleh menimpa / masuk statistik
        if (!$isCustom) {
            $state['usage_stats'] = [
                'history' => array_values($history),
                'updated_at' => $now,
            ];
            return $state;
        }

        // Cari entri terbaru untuk slot yang sama
        $latestIdx = null;
        foreach ($history as $i => $h) {
            if ((string) ($h['id'] ?? '') !== $id) {
                continue;
            }
            if ($latestIdx === null
                || strcmp((string) ($h['last_at'] ?? ''), (string) ($history[$latestIdx]['last_at'] ?? '')) >= 0
            ) {
                $latestIdx = $i;
            }
        }

        if ($incrementTimes) {
            // Siklus pakai baru: jika nama sama dengan entri terbaru → tambah times;
            // jika nama berbeda → entri baru (nama lama tetap di statistik)
            if ($latestIdx !== null
                && strcasecmp(trim((string) ($history[$latestIdx]['nama'] ?? '')), $nama) === 0
            ) {
                $history[$latestIdx]['nama'] = $nama;
                $history[$latestIdx]['last_at'] = $now;
                $history[$latestIdx]['times'] = max(1, (int) ($history[$latestIdx]['times'] ?? 0)) + 1;
            } else {
                $history[] = [
                    'id' => $id,
                    'nama' => $nama,
                    'first_at' => $now,
                    'last_at' => $now,
                    'times' => 1,
                ];
            }
        } elseif ($latestIdx !== null) {
            // Soft update (rename saat masih dipakai / expire): perbarui entri terbaru
            $latestNama = trim((string) ($history[$latestIdx]['nama'] ?? ''));
            if (strcasecmp($latestNama, $nama) === 0) {
                $history[$latestIdx]['last_at'] = $now;
                $history[$latestIdx]['times'] = max(1, (int) ($history[$latestIdx]['times'] ?? 1));
            } else {
                // Rename di tengah pemakaian → update nama entri aktif, jangan buat baris baru
                $history[$latestIdx]['nama'] = $nama;
                $history[$latestIdx]['last_at'] = $now;
                $history[$latestIdx]['times'] = max(1, (int) ($history[$latestIdx]['times'] ?? 1));
            }
        } else {
            $history[] = [
                'id' => $id,
                'nama' => $nama,
                'first_at' => $now,
                'last_at' => $now,
                'times' => 1,
            ];
        }

        $state['usage_stats'] = [
            'history' => array_values($history),
            'updated_at' => $now,
        ];
        return $state;
    }

    /** Username admin bawaan untuk ID sekolah (SCH03 → admin3). */
    public static function adminUsernameForId(string $sekolahId): string
    {
        if (preg_match('/^SCH(\d+)$/i', trim($sekolahId), $m)) {
            return 'admin' . (int) $m[1];
        }
        return '';
    }

    /** Nama default seed: "Sekolah 3" untuk SCH03, dll. */
    private function isCustomNama(string $nama, string $sekolahId): bool
    {
        $nama = trim($nama);
        if ($nama === '') {
            return false;
        }
        $n = 0;
        if (preg_match('/^SCH(\d+)$/i', $sekolahId, $m)) {
            $n = (int) $m[1];
        }
        if ($n > 0 && strcasecmp($nama, 'Sekolah ' . $n) === 0) {
            return false;
        }
        if (preg_match('/^Sekolah\s+\d+$/iu', $nama)) {
            return false;
        }
        return true;
    }

    /** @param list<string> $recent @return list<string> */
    private function pushRecent(array $recent, string $id, int $keep = 20): array
    {
        $out = [$id];
        foreach ($recent as $rid) {
            $rid = (string) $rid;
            if ($rid === '' || $rid === $id) {
                continue;
            }
            $out[] = $rid;
            if (count($out) >= $keep) {
                break;
            }
        }
        return $out;
    }

    /**
     * @param array{
     *   id?:string,nama?:string,kepala_nama?:string,kepala_nip?:string,
     *   alamat?:string,tempat_cetak?:string,tanggal_cetak?:string,keterangan?:string
     * } $input
     */
    public function save(array $input): array
    {
        $state = $this->state();
        $id = trim((string) ($input['id'] ?? ''));
        $nama = trim((string) ($input['nama'] ?? ''));
        if ($nama === '') {
            throw new InvalidArgumentException('Nama sekolah wajib diisi.');
        }
        if (mb_strlen($nama) > 120) {
            throw new InvalidArgumentException('Nama sekolah terlalu panjang.');
        }

        $alamat = trim((string) ($input['alamat'] ?? ''));
        if (mb_strlen($alamat) > 300) {
            throw new InvalidArgumentException('Alamat terlalu panjang (maks. 300 karakter).');
        }

        $fields = [
            'nama' => $nama,
            'kepala_nama' => trim((string) ($input['kepala_nama'] ?? '')),
            'kepala_nip' => trim((string) ($input['kepala_nip'] ?? '')),
            'alamat' => $alamat,
            'tempat_cetak' => trim((string) ($input['tempat_cetak'] ?? '')),
            'tanggal_cetak' => $this->normalizeTanggal((string) ($input['tanggal_cetak'] ?? '')),
            'keterangan' => trim((string) ($input['keterangan'] ?? '')),
            'aktif' => !isset($input['aktif']) || filter_var($input['aktif'], FILTER_VALIDATE_BOOLEAN),
            'updated_at' => date('c'),
        ];

        if ($id !== '') {
            $found = false;
            $wasDipakai = false;
            $becameNewUse = false;
            foreach ($state['sekolah'] as &$row) {
                if ($row['id'] !== $id) {
                    continue;
                }
                $oldNama = trim((string) ($row['nama'] ?? ''));
                $wasDipakai = !empty($row['dipakai']);
                // Tanda "sudah dipakai" = nama diedit lalu disimpan
                if ($nama !== $oldNama || $this->isCustomNama($nama, $id)) {
                    $fields['dipakai'] = true;
                    $fields['last_used_at'] = date('c');
                    if (trim((string) ($row['dipakai_at'] ?? '')) === '') {
                        $fields['dipakai_at'] = date('c');
                        $becameNewUse = true;
                    }
                }
                $row = array_merge($row, $fields);
                $found = true;
                $saved = $row;
                break;
            }
            unset($row);
            if (!$found) {
                throw new InvalidArgumentException('Sekolah tidak ditemukan.');
            }
            if (!empty($saved['dipakai'])) {
                $state['active_id'] = $saved['id'];
                $state['recent_ids'] = $this->pushRecent($state['recent_ids'] ?? [], (string) $saved['id']);
                $state = $this->recordUsageInState(
                    $state,
                    (string) $saved['id'],
                    (string) $saved['nama'],
                    !$wasDipakai || $becameNewUse
                );
            }
            $this->write($state);
            return $saved;
        }

        $now = date('c');
        $newId = $this->newId();
        $markDipakai = $this->isCustomNama($nama, $newId);
        $saved = array_merge($this->blankSekolah(), $fields, [
            'id' => $newId,
            'aktif' => true,
            'dipakai' => $markDipakai,
            'dipakai_at' => $markDipakai ? $now : '',
            'last_used_at' => $markDipakai ? $now : '',
            'created_at' => $now,
        ]);
        $state['sekolah'][] = $saved;
        $state['active_id'] = $saved['id'];
        if ($markDipakai) {
            $state['recent_ids'] = $this->pushRecent($state['recent_ids'] ?? [], $saved['id']);
            $state = $this->recordUsageInState($state, $saved['id'], $saved['nama'], true);
        }
        $this->write($state);
        $this->ensureAdminForSaved($saved['id']);
        return $saved;
    }

    /** Buat/pastikan akun adminN untuk sekolah baru. */
    private function ensureAdminForSaved(string $sekolahId): void
    {
        try {
            require_once __DIR__ . '/UserStore.php';
            (new UserStore())->ensureAdminForSekolah($sekolahId);
        } catch (Throwable) {
            // Jangan gagalkan simpan sekolah jika seed admin gagal.
        }
    }

    public function delete(string $id): void
    {
        $state = $this->state();
        if (count($state['sekolah']) <= 1) {
            throw new InvalidArgumentException('Minimal harus ada satu sekolah.');
        }
        $target = $this->find($id, $state);
        if ($target === null) {
            throw new InvalidArgumentException('Sekolah tidak ditemukan.');
        }
        if (!empty($target['logo_file'])) {
            $this->removeLogoFile((string) $target['logo_file']);
        }
        $state['sekolah'] = array_values(array_filter(
            $state['sekolah'],
            static fn ($s) => $s['id'] !== $id
        ));
        $state['recent_ids'] = array_values(array_filter(
            $state['recent_ids'] ?? [],
            static fn ($rid) => (string) $rid !== $id
        ));
        if ($state['active_id'] === $id) {
            $state['active_id'] = $state['sekolah'][0]['id'];
        }
        $this->write($state);
    }

    /**
     * Unggah logo sekolah (png/jpg/webp/gif), maks ~2MB.
     *
     * @param array $file elemen $_FILES
     */
    public function uploadLogo(string $id, array $file): array
    {
        $state = $this->state();
        $idx = null;
        foreach ($state['sekolah'] as $i => $row) {
            if ($row['id'] === $id) {
                $idx = $i;
                break;
            }
        }
        if ($idx === null) {
            throw new InvalidArgumentException('Sekolah tidak ditemukan.');
        }

        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            throw new InvalidArgumentException('Gagal mengunggah logo.');
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        if ($tmp === '' || !is_uploaded_file($tmp)) {
            throw new InvalidArgumentException('File logo tidak valid.');
        }
        $size = (int) ($file['size'] ?? 0);
        if ($size <= 0 || $size > 2 * 1024 * 1024) {
            throw new InvalidArgumentException('Ukuran logo maksimal 2 MB.');
        }

        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($tmp) ?: '';
        $extMap = [
            'image/png' => 'png',
            'image/jpeg' => 'jpg',
            'image/webp' => 'webp',
            'image/gif' => 'gif',
        ];
        if (!isset($extMap[$mime])) {
            throw new InvalidArgumentException('Logo harus PNG, JPG, WEBP, atau GIF.');
        }
        $ext = $extMap[$mime];

        if (!is_dir($this->logoDir)) {
            mkdir($this->logoDir, 0777, true);
        }

        $old = (string) ($state['sekolah'][$idx]['logo_file'] ?? '');
        $name = $id . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
        $dest = $this->logoDir . '/' . $name;
        if (!move_uploaded_file($tmp, $dest)) {
            throw new RuntimeException('Gagal menyimpan logo.');
        }
        @chmod($dest, 0666);

        if ($old !== '' && $old !== $name) {
            $this->removeLogoFile($old);
        }

        $state['sekolah'][$idx]['logo_file'] = $name;
        $state['sekolah'][$idx]['updated_at'] = date('c');
        $this->write($state);

        return $this->withLogoUrl($state['sekolah'][$idx]);
    }

    public function clearLogo(string $id): array
    {
        $state = $this->state();
        foreach ($state['sekolah'] as &$row) {
            if ($row['id'] !== $id) {
                continue;
            }
            if (!empty($row['logo_file'])) {
                $this->removeLogoFile((string) $row['logo_file']);
            }
            $row['logo_file'] = '';
            $row['updated_at'] = date('c');
            $saved = $row;
            $this->write($state);
            return $this->withLogoUrl($saved);
        }
        unset($row);
        throw new InvalidArgumentException('Sekolah tidak ditemukan.');
    }

    public function logoPath(?string $logoFile): ?string
    {
        $logoFile = basename((string) $logoFile);
        if ($logoFile === '') {
            return null;
        }
        $path = $this->logoDir . '/' . $logoFile;
        return is_file($path) ? $path : null;
    }

    /** @return list<array> */
    public function listForApi(): array
    {
        $state = $this->state();
        $currentId = self::$contextId ?? $state['active_id'];
        $out = [];
        foreach ($state['sekolah'] as $row) {
            $item = $this->withLogoUrl($row);
            // Semua sekolah berstatus aktif (multi-sekolah)
            $item['aktif'] = !array_key_exists('aktif', $row) || !empty($row['aktif']);
            $item['dipakai'] = !empty($row['dipakai'])
                || $this->isCustomNama((string) ($row['nama'] ?? ''), (string) ($row['id'] ?? ''));
            $item['utama'] = $row['id'] === 'SCH01';
            $item['dipakai_at'] = (string) ($row['dipakai_at'] ?? '');
            $item['expire_days'] = $row['id'] === 'SCH01' ? null : 7;
            $item['current'] = $row['id'] === $currentId;
            $out[] = $item;
        }
        return $out;
    }

    public function activeForApi(): array
    {
        $a = $this->withLogoUrl($this->active());
        $a['aktif'] = true;
        $a['current'] = true;
        return $a;
    }

    /** Tempat & tanggal untuk blok tanda tangan cetak. */
    public function blokCetak(?array $sekolah = null): array
    {
        $s = $sekolah ?? $this->active();
        $tempat = trim((string) ($s['tempat_cetak'] ?? ''));
        if ($tempat === '') {
            $tempat = 'Sleman';
        }
        $tgl = trim((string) ($s['tanggal_cetak'] ?? ''));
        if ($tgl === '') {
            $tgl = date('Y-m-d');
        }
        $ts = strtotime($tgl) ?: time();
        $bulan = [
            1 => 'Januari', 2 => 'Februari', 3 => 'Maret', 4 => 'April',
            5 => 'Mei', 6 => 'Juni', 7 => 'Juli', 8 => 'Agustus',
            9 => 'September', 10 => 'Oktober', 11 => 'November', 12 => 'Desember',
        ];
        $label = (int) date('j', $ts) . ' ' . $bulan[(int) date('n', $ts)] . ' ' . date('Y', $ts);

        return [
            'tempat' => $tempat,
            'tanggal' => date('Y-m-d', $ts),
            'tanggal_label' => $label,
            'tempat_tanggal' => $tempat . ', ' . $label,
            'kepala_nama' => (string) ($s['kepala_nama'] ?? ''),
            'kepala_nip' => (string) ($s['kepala_nip'] ?? ''),
        ];
    }

    private function withLogoUrl(array $row): array
    {
        // Migrasi ringan: isi alamat dari keterangan lama jika kosong
        if (trim((string) ($row['alamat'] ?? '')) === '' && trim((string) ($row['keterangan'] ?? '')) !== '') {
            $row['alamat'] = (string) $row['keterangan'];
        }
        $file = (string) ($row['logo_file'] ?? '');
        $row['logo_url'] = $file !== '' && $this->logoPath($file)
            ? ('logo_sekolah.php?f=' . rawurlencode($file) . '&v=' . substr(md5($file . ($row['updated_at'] ?? '')), 0, 8))
            : '';
        return $row;
    }

    private function defaultSekolah(): array
    {
        return array_merge($this->blankSekolah(), [
            'id' => 'SCH01',
            'nama' => 'Sekolah 1',
            'alamat' => '',
            'tempat_cetak' => '',
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ]);
    }

    private function blankSekolah(): array
    {
        return [
            'id' => '',
            'nama' => '',
            'kepala_nama' => '',
            'kepala_nip' => '',
            'alamat' => '',
            'logo_file' => '',
            'tempat_cetak' => '',
            'tanggal_cetak' => '',
            'keterangan' => '',
            'aktif' => true,
            'dipakai' => false,
            'dipakai_at' => '',
            'last_used_at' => '',
            'created_at' => '',
            'updated_at' => '',
        ];
    }

    /**
     * Pastikan ada 100 sekolah dengan ID otomatis SCH01–SCH100.
     * Data sekolah yang sudah ada (nama/alamat/logo) dipertahankan.
     *
     * @param array{active_id:?string,sekolah:list<array>,seeded_ten?:bool,seeded_hundred?:bool} $state
     * @return array{active_id:?string,sekolah:list<array>,seeded_ten?:bool,seeded_hundred?:bool,recent_ids?:list<string>}
     */
    private function ensureSchoolSlots(array $state): array
    {
        $need = self::SCHOOL_SLOT_COUNT;
        $seedPath = dirname($this->jsonPath) . '/sekolah.seed.json';
        if (is_readable($seedPath)) {
            $seedJson = json_decode((string) file_get_contents($seedPath), true);
            if (is_array($seedJson) && count($seedJson['sekolah'] ?? []) >= $need) {
                $list = [];
                foreach ($seedJson['sekolah'] as $row) {
                    if (!is_array($row) || trim((string) ($row['id'] ?? '')) === '') {
                        continue;
                    }
                    $list[] = array_merge($this->blankSekolah(), $row);
                }
                if (count($list) >= $need) {
                    $state = [
                        'active_id' => (string) ($seedJson['active_id'] ?? $list[0]['id']),
                        'sekolah' => array_slice($list, 0, $need),
                        'seeded_ten' => true,
                        'seeded_hundred' => true,
                        'recent_ids' => $state['recent_ids'] ?? [],
                    ];
                    try {
                        $this->write($state);
                        @unlink($seedPath);
                    } catch (Throwable) {
                    }
                    return $state;
                }
            }
        }

        if (!empty($state['seeded_hundred']) && count($state['sekolah']) >= $need) {
            return $state;
        }

        $old = $state['sekolah'];
        $oldActive = (string) ($state['active_id'] ?? '');
        $byId = [];
        foreach ($old as $row) {
            $id = (string) ($row['id'] ?? '');
            if ($id !== '') {
                $byId[$id] = $row;
            }
        }

        $now = date('c');
        $list = [];
        for ($n = 1; $n <= $need; $n++) {
            $id = sprintf('SCH%02d', $n);
            $prev = $byId[$id] ?? null;
            $base = is_array($prev)
                ? array_merge($this->blankSekolah(), $prev)
                : $this->blankSekolah();

            $alamat = trim((string) ($base['alamat'] ?? ''));
            if ($alamat === '' && trim((string) ($base['keterangan'] ?? '')) !== '') {
                $alamat = trim((string) $base['keterangan']);
            }

            $existingNama = trim((string) ($base['nama'] ?? ''));
            $list[] = array_merge($base, [
                'id' => $id,
                'nama' => $existingNama !== '' ? $existingNama : ('Sekolah ' . $n),
                'alamat' => $alamat,
                'aktif' => true,
                'created_at' => (string) (($base['created_at'] ?? '') !== '' ? $base['created_at'] : $now),
                'updated_at' => (string) (($base['updated_at'] ?? '') !== '' ? $base['updated_at'] : $now),
            ]);
        }

        $activeId = 'SCH01';
        if ($oldActive !== '' && $this->find($oldActive, ['sekolah' => $list])) {
            $activeId = $oldActive;
        }

        $state['sekolah'] = $list;
        $state['active_id'] = $activeId;
        $state['seeded_ten'] = true;
        $state['seeded_hundred'] = true;
        try {
            $this->write($state);
        } catch (Throwable) {
        }
        return $state;
    }

    private function normalizeTanggal(string $raw): string
    {
        $raw = trim($raw);
        if ($raw === '') {
            return '';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $raw)) {
            return $raw;
        }
        $ts = strtotime($raw);
        return $ts ? date('Y-m-d', $ts) : '';
    }

    /** ID otomatis: SCH01, SCH02, … */
    private function newId(): string
    {
        $state = $this->read();
        $max = 0;
        foreach ($state['sekolah'] as $row) {
            $id = (string) ($row['id'] ?? '');
            if (preg_match('/^SCH(\d+)$/i', $id, $m)) {
                $max = max($max, (int) $m[1]);
            }
        }
        return sprintf('SCH%02d', $max + 1);
    }

    private function find(?string $id, array $state): ?array
    {
        if ($id === null || $id === '') {
            return null;
        }
        foreach ($state['sekolah'] as $row) {
            if ($row['id'] === $id) {
                return $row;
            }
        }
        return null;
    }

    private function removeLogoFile(string $name): void
    {
        $name = basename($name);
        if ($name === '') {
            return;
        }
        $path = $this->logoDir . '/' . $name;
        if (is_file($path)) {
            @unlink($path);
        }
    }

    /** @return array{active_id:?string,sekolah:list<array>,seeded_ten?:bool,seeded_hundred?:bool,recent_ids?:list<string>} */
    private function read(): array
    {
        if (!is_readable($this->jsonPath)) {
            return ['active_id' => null, 'sekolah' => [], 'seeded_ten' => false, 'seeded_hundred' => false, 'recent_ids' => []];
        }
        $json = json_decode((string) file_get_contents($this->jsonPath), true);
        if (!is_array($json)) {
            return ['active_id' => null, 'sekolah' => [], 'seeded_ten' => false, 'seeded_hundred' => false, 'recent_ids' => []];
        }
        $list = [];
        foreach ($json['sekolah'] ?? [] as $row) {
            if (!is_array($row) || trim((string) ($row['id'] ?? '')) === '') {
                continue;
            }
            $list[] = array_merge($this->blankSekolah(), $row);
        }
        $recent = [];
        foreach ($json['recent_ids'] ?? [] as $rid) {
            $rid = trim((string) $rid);
            if ($rid !== '') {
                $recent[] = $rid;
            }
        }
        return [
            'active_id' => isset($json['active_id']) ? (string) $json['active_id'] : null,
            'sekolah' => $list,
            'seeded_ten' => !empty($json['seeded_ten']),
            'seeded_hundred' => !empty($json['seeded_hundred']),
            'recent_ids' => $recent,
            'usage_stats' => is_array($json['usage_stats'] ?? null) ? $json['usage_stats'] : ['history' => []],
        ];
    }

    /** @param array{active_id:?string,sekolah:list<array>,seeded_ten?:bool,seeded_hundred?:bool,recent_ids?:list<string>,usage_stats?:array} $state */
    private function write(array $state): void
    {
        require_once __DIR__ . '/Security.php';
        $payload = [
            'active_id' => $state['active_id'] ?? null,
            'seeded_ten' => !empty($state['seeded_ten']),
            'seeded_hundred' => !empty($state['seeded_hundred']),
            'recent_ids' => array_values($state['recent_ids'] ?? []),
            'usage_stats' => $state['usage_stats'] ?? ['history' => []],
            'sekolah' => $state['sekolah'] ?? [],
        ];
        try {
            Security::writeJsonFile($this->jsonPath, $payload);
        } catch (Throwable) {
            throw new RuntimeException('Gagal menyimpan pengaturan sekolah.');
        }
    }
}
