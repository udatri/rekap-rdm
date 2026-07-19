<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/RekapService.php';

Auth::startSession();
Security::sendHeaders(true);

/**
 * @return array{user:?array,capabilities:array<string,bool>,roles:array<string,string>,csrf:string}
 */
function authPayload(?array $user): array
{
    $caps = [
        'view_rekap', 'export', 'ujian', 'impor', 'kelas', 'sekolah', 'sekolah_manage',
        'users', 'users_superadmin', 'bobot_ijazah', 'olah_rapor',
    ];
    $capabilities = [];
    foreach ($caps as $c) {
        $capabilities[$c] = Auth::can($c, $user);
    }
    $roles = UserStore::ROLES;
    if (!Auth::can('users_superadmin', $user)) {
        unset($roles[UserStore::ROLE_SUPERADMIN]);
    }
    return [
        'user' => $user,
        'capabilities' => $capabilities,
        'roles' => $roles,
        'csrf' => Security::csrfToken(),
    ];
}

function requireCapability(string $cap): void
{
    if (!Auth::can($cap)) {
        throw new RuntimeException('FORBIDDEN');
    }
}

try {
    $method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));
    $action = $_GET['action'] ?? $_POST['action'] ?? 'filters';

    $input = $_POST;
    $contentType = $_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '';
    if (str_contains($contentType, 'application/json')) {
        $raw = file_get_contents('php://input');
        if ($raw) {
            $jsonBody = json_decode($raw, true);
            if (is_array($jsonBody)) {
                $input = array_merge($input, $jsonBody);
                if (isset($jsonBody['action'])) {
                    $action = (string) $jsonBody['action'];
                }
            }
        }
    } elseif ($raw = file_get_contents('php://input')) {
        $jsonBody = json_decode($raw, true);
        if (is_array($jsonBody)) {
            $input = array_merge($input, $jsonBody);
            if (isset($jsonBody['action'])) {
                $action = (string) $jsonBody['action'];
            }
        }
    }
    $action = trim((string) $action);

    // Auth publik (tanpa login)
    if ($action === 'login') {
        if ($method !== 'POST') {
            throw new RuntimeException('METHOD');
        }
        $user = Auth::login(
            trim((string) ($input['username'] ?? '')),
            (string) ($input['password'] ?? '')
        );
        echo json_encode(['ok' => true, 'data' => authPayload($user)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'logout') {
        if ($method !== 'POST') {
            throw new RuntimeException('METHOD');
        }
        // CSRF opsional jika sesi sudah habis
        $tok = (string) ($input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        if (Auth::check() && $tok !== '') {
            Security::validateCsrf($tok);
        }
        Auth::logout();
        echo json_encode(['ok' => true, 'data' => ['message' => 'Keluar.', 'csrf' => Security::csrfToken()]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'csrf') {
        echo json_encode(['ok' => true, 'data' => ['csrf' => Security::csrfToken()]], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($action === 'me') {
        $user = Auth::user();
        echo json_encode(['ok' => true, 'data' => authPayload($user)], JSON_UNESCAPED_UNICODE);
        exit;
    }

    Auth::requireLogin();

    // Mutasi hanya POST + CSRF
    if (!Security::isReadAction($action)) {
        if ($method !== 'POST') {
            throw new RuntimeException('METHOD');
        }
        $tok = (string) ($input['csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
        Security::validateCsrf($tok);
    }

    // Admin terikat ke sekolahnya; branding & edit memakai sekolah itu
    $authUser = Auth::user();
    if (is_array($authUser)) {
        $role = (string) ($authUser['role'] ?? '');
        $sid = trim((string) ($authUser['sekolah_id'] ?? ''));
        if ($role !== UserStore::ROLE_SUPERADMIN && $sid !== '') {
            require_once __DIR__ . '/lib/SekolahStore.php';
            SekolahStore::setContextId($sid);
        }
    }

    $actionCaps = [
        'health' => 'impor',
        'list_import' => 'impor',
        'upload_excel' => 'impor',
        'import_cloud' => 'impor',
        'delete_import' => 'impor',
        'delete_import_all' => 'impor',
        'refresh' => 'impor',
        'filters' => 'view_rekap',
        'per_semester' => 'view_rekap',
        'semua_semester' => 'view_rekap',
        'per_siswa' => 'view_rekap',
        'nilai_ijazah' => 'view_rekap',
        'ijazah_bobot' => 'view_rekap',
        'siswa_kelas' => 'view_rekap',
        'list_kelas' => 'view_rekap',
        'list_sekolah' => 'view_rekap',
        'save_ijazah_bobot' => 'bobot_ijazah',
        'add_kelas' => 'kelas',
        'delete_kelas' => 'kelas',
        'save_sekolah' => 'sekolah',
        'delete_sekolah' => 'sekolah_manage',
        'set_sekolah_aktif' => 'sekolah_manage',
        'upload_logo_sekolah' => 'sekolah',
        'clear_logo_sekolah' => 'sekolah',
        'ujian_templates' => 'ujian',
        'list_ujian' => 'ujian',
        'get_ujian' => 'ujian',
        'create_ujian' => 'ujian',
        'update_ujian' => 'ujian',
        'save_nilai_ujian' => 'ujian',
        'delete_ujian' => 'ujian',
        'import_ujian_excel' => 'ujian',
        'mapel_kelas' => 'ujian',
        'list_users' => 'users',
        'save_user' => 'users',
        'delete_user' => 'users',
        'change_password' => 'view_rekap',
        'list_konversi' => 'olah_rapor',
        'save_konversi' => 'olah_rapor',
        'delete_konversi' => 'olah_rapor',
        'uji_konversi' => 'olah_rapor',
        'save_konversi_settings' => 'olah_rapor',
        'run_konversi' => 'olah_rapor',
        'import_konversi' => 'olah_rapor',
        'list_rapor_nilai' => 'olah_rapor',
        'get_rapor_nilai' => 'olah_rapor',
        'create_rapor_nilai' => 'olah_rapor',
        'update_rapor_nilai' => 'olah_rapor',
        'delete_rapor_nilai' => 'olah_rapor',
    ];
    if (isset($actionCaps[$action])) {
        requireCapability($actionCaps[$action]);
    }

    $service = new RekapService();

    $force = isset($_GET['refresh']) && $_GET['refresh'] === '1';
    $lightActions = [
        'health', 'list_import', 'upload_excel', 'import_cloud', 'delete_import', 'delete_import_all',
        'list_sekolah', 'save_sekolah', 'delete_sekolah', 'set_sekolah_aktif',
        'upload_logo_sekolah', 'clear_logo_sekolah',
        'list_users', 'save_user', 'delete_user', 'change_password',
        'list_konversi', 'save_konversi', 'delete_konversi', 'uji_konversi',
        'save_konversi_settings', 'run_konversi',
        'list_rapor_nilai', 'get_rapor_nilai', 'delete_rapor_nilai', 'update_rapor_nilai',
    ];
    // import_konversi & create_rapor_nilai butuh data siswa dari cache
    $data = in_array($action, $lightActions, true)
        ? null
        : $service->ensureData($force || $action === 'refresh');

    $q = [
        'tahun_ajaran' => $_GET['tahun_ajaran'] ?? ($input['tahun_ajaran'] ?? ''),
        'semester' => $_GET['semester'] ?? ($input['semester'] ?? ''),
        'semester_ke' => $_GET['semester_ke'] ?? '',
        'kelas' => $_GET['kelas'] ?? ($input['kelas'] ?? ''),
        'id' => $_GET['id'] ?? ($input['id'] ?? ''),
        'jenis' => $_GET['jenis'] ?? ($input['jenis'] ?? ''),
    ];

    $payload = match ($action) {
        'list_users' => [
            'users' => (function () {
                $actor = Auth::requireLogin();
                $all = Auth::users()->allPublic();
                if (!Auth::can('users_superadmin')) {
                    $sid = trim((string) ($actor['sekolah_id'] ?? ''));
                    $all = array_values(array_filter(
                        $all,
                        static function ($u) use ($sid) {
                            if (($u['role'] ?? '') === UserStore::ROLE_SUPERADMIN) {
                                return false;
                            }
                            // Admin hanya lihat user di sekolahnya (+ tanpa sekolah kosong tidak)
                            return $sid !== '' && trim((string) ($u['sekolah_id'] ?? '')) === $sid;
                        }
                    ));
                }
                return $all;
            })(),
            'roles' => (function () {
                $roles = UserStore::ROLES;
                if (!Auth::can('users_superadmin')) {
                    unset($roles[UserStore::ROLE_SUPERADMIN]);
                }
                return $roles;
            })(),
            'me' => Auth::user(),
        ],
        'save_user' => (function () use ($input) {
            $actor = Auth::requireLogin();
            if (!Auth::can('users_superadmin')) {
                $sid = trim((string) ($actor['sekolah_id'] ?? ''));
                if ($sid === '') {
                    throw new RuntimeException('FORBIDDEN');
                }
                $input['sekolah_id'] = $sid;
                if (($input['role'] ?? '') === UserStore::ROLE_SUPERADMIN) {
                    throw new RuntimeException('FORBIDDEN');
                }
                $editId = trim((string) ($input['id'] ?? ''));
                if ($editId !== '') {
                    $existing = Auth::users()->findById($editId);
                    if ($existing === null
                        || trim((string) ($existing['sekolah_id'] ?? '')) !== $sid
                        || ($existing['role'] ?? '') === UserStore::ROLE_SUPERADMIN
                    ) {
                        throw new RuntimeException('FORBIDDEN');
                    }
                }
            }
            $saved = Auth::users()->save($input, (string) $actor['role']);
            return [
                'message' => 'Pengguna disimpan.',
                'user' => $saved,
                'users' => (function () {
                    $actor = Auth::user();
                    $all = Auth::users()->allPublic();
                    if (!Auth::can('users_superadmin')) {
                        $sid = trim((string) ($actor['sekolah_id'] ?? ''));
                        $all = array_values(array_filter(
                            $all,
                            static fn ($u) => ($u['role'] ?? '') !== UserStore::ROLE_SUPERADMIN
                                && $sid !== ''
                                && trim((string) ($u['sekolah_id'] ?? '')) === $sid
                        ));
                    }
                    return $all;
                })(),
            ];
        })(),
        'delete_user' => (function () use ($input) {
            $actor = Auth::requireLogin();
            $targetId = trim((string) ($input['id'] ?? ''));
            if (!Auth::can('users_superadmin')) {
                $sid = trim((string) ($actor['sekolah_id'] ?? ''));
                $existing = Auth::users()->findById($targetId);
                if ($existing === null
                    || $sid === ''
                    || trim((string) ($existing['sekolah_id'] ?? '')) !== $sid
                    || ($existing['role'] ?? '') === UserStore::ROLE_SUPERADMIN
                ) {
                    throw new RuntimeException('FORBIDDEN');
                }
            }
            Auth::users()->delete(
                $targetId,
                (string) $actor['id'],
                (string) $actor['role']
            );
            return [
                'message' => 'Pengguna dihapus.',
                'users' => (function () {
                    $actor = Auth::user();
                    $all = Auth::users()->allPublic();
                    if (!Auth::can('users_superadmin')) {
                        $sid = trim((string) ($actor['sekolah_id'] ?? ''));
                        $all = array_values(array_filter(
                            $all,
                            static fn ($u) => ($u['role'] ?? '') !== UserStore::ROLE_SUPERADMIN
                                && $sid !== ''
                                && trim((string) ($u['sekolah_id'] ?? '')) === $sid
                        ));
                    }
                    return $all;
                })(),
            ];
        })(),
        'change_password' => (function () use ($input) {
            $actor = Auth::requireLogin();
            Auth::users()->changePassword(
                (string) $actor['id'],
                (string) ($input['old_password'] ?? ''),
                (string) ($input['new_password'] ?? '')
            );
            return ['message' => 'Password berhasil diubah.'];
        })(),
        'health' => Config::health(),
        'list_import' => [
            'health' => Config::health(),
            'files' => $service->importService()->listFiles(),
        ],
        'upload_excel' => (function () use ($service, $input) {
            if (empty($_FILES['files']) && empty($_FILES['file'])) {
                throw new InvalidArgumentException('Pilih file .xlsx dari komputer.');
            }
            $field = $_FILES['files'] ?? $_FILES['file'];
            $result = $service->importService()->uploadMany($field);
            if ($result['saved'] === []) {
                throw new RuntimeException(
                    'Tidak ada file yang tersimpan. ' . implode(' ', $result['errors'])
                );
            }
            $skipRefresh = !empty($input['skip_refresh']) || (($_GET['skip_refresh'] ?? '') === '1');
            $fresh = $skipRefresh ? null : $service->ensureData(true);
            return [
                'message' => count($result['saved']) . ' file berhasil diunggah.',
                'saved' => $result['saved'],
                'errors' => $result['errors'],
                'files' => $service->importService()->listFiles(),
                'records' => $fresh ? count($fresh['records']) : null,
                'students' => $fresh ? count($fresh['students']) : null,
            ];
        })(),
        'import_cloud' => (function () use ($service, $input) {
            $url = trim((string) ($input['url'] ?? ''));
            $filename = trim((string) ($input['filename'] ?? ''));
            $saved = $service->importService()->importFromUrl($url, $filename !== '' ? $filename : null);
            $fresh = $service->ensureData(true);
            return [
                'message' => 'File cloud berhasil diimpor: ' . $saved['name'],
                'saved' => $saved,
                'files' => $service->importService()->listFiles(),
                'records' => count($fresh['records']),
                'students' => count($fresh['students']),
            ];
        })(),
        'delete_import' => (function () use ($service, $input) {
            $name = trim((string) ($input['name'] ?? ''));
            $service->importService()->deleteFile($name);
            $fresh = $service->ensureData(true);
            return [
                'message' => 'File dihapus: ' . $name,
                'files' => $service->importService()->listFiles(),
                'records' => count($fresh['records']),
                'students' => count($fresh['students']),
            ];
        })(),
        'delete_import_all' => (function () use ($service) {
            $result = $service->importService()->deleteAllFiles();
            $fresh = $service->ensureData(true);
            $msg = 'Dihapus ' . $result['deleted'] . ' file.';
            if ($result['failed'] !== []) {
                $msg .= ' Gagal: ' . implode('; ', $result['failed']);
            }
            return [
                'message' => $msg,
                'deleted' => $result['deleted'],
                'failed' => $result['failed'],
                'files' => $service->importService()->listFiles(),
                'records' => count($fresh['records']),
                'students' => count($fresh['students']),
            ];
        })(),
        'filters' => $service->filters($data),
        'per_semester' => $service->rekapPerSemester($data, $q),
        'semua_semester' => $service->rekapSemuaSemester($data, $q),
        'per_siswa' => $service->rekapPerSiswa($data, $q),
        'list_kelas' => $service->listKelas($data),
        'list_sekolah' => [
            'sekolah' => $service->sekolahStore()->listForApi(),
            'aktif' => $service->sekolahStore()->activeForApi(),
            'cetak' => $service->sekolahStore()->blokCetak(),
            'usage' => $service->sekolahStore()->usageStats(),
        ],
        'save_sekolah' => (function () use ($service, $input) {
            if (!Auth::can('sekolah_manage')) {
                $user = Auth::user();
                $boundId = trim((string) ($user['sekolah_id'] ?? ''));
                if ($boundId === '') {
                    $boundId = (string) ($service->sekolahStore()->active()['id'] ?? '');
                }
                $reqId = trim((string) ($input['id'] ?? ''));
                if ($boundId === '' || $reqId === '' || $reqId !== $boundId) {
                    throw new RuntimeException('FORBIDDEN');
                }
                $input['id'] = $boundId;
                SekolahStore::setContextId($boundId);
            }
            $saved = $service->sekolahStore()->save($input);
            return [
                'message' => 'Pengaturan sekolah disimpan.',
                'sekolah' => $service->sekolahStore()->listForApi(),
                'aktif' => $service->sekolahStore()->activeForApi(),
                'usage' => $service->sekolahStore()->usageStats(),
                'item' => $saved,
            ];
        })(),
        'delete_sekolah' => (function () use ($service, $input) {
            $service->sekolahStore()->delete((string) ($input['id'] ?? ''));
            return [
                'message' => 'Sekolah dihapus.',
                'sekolah' => $service->sekolahStore()->listForApi(),
                'aktif' => $service->sekolahStore()->activeForApi(),
                'usage' => $service->sekolahStore()->usageStats(),
            ];
        })(),
        'set_sekolah_aktif' => (function () use ($service, $input) {
            $aktif = $service->sekolahStore()->setActive((string) ($input['id'] ?? ''));
            return [
                'message' => 'Sekolah aktif: ' . ($aktif['nama'] ?? ''),
                'sekolah' => $service->sekolahStore()->listForApi(),
                'aktif' => $service->sekolahStore()->activeForApi(),
                'usage' => $service->sekolahStore()->usageStats(),
            ];
        })(),
        'upload_logo_sekolah' => (function () use ($service, $input) {
            $id = trim((string) ($input['id'] ?? $_POST['id'] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException('ID sekolah wajib.');
            }
            if (!Auth::can('sekolah_manage')) {
                $user = Auth::user();
                $boundId = trim((string) ($user['sekolah_id'] ?? ''));
                if ($boundId === '') {
                    $boundId = (string) ($service->sekolahStore()->active()['id'] ?? '');
                }
                if ($id !== $boundId) {
                    throw new RuntimeException('FORBIDDEN');
                }
            }
            if (empty($_FILES['logo'])) {
                throw new InvalidArgumentException('Pilih file logo.');
            }
            $item = $service->sekolahStore()->uploadLogo($id, $_FILES['logo']);
            return [
                'message' => 'Logo berhasil diunggah.',
                'item' => $item,
                'sekolah' => $service->sekolahStore()->listForApi(),
                'aktif' => $service->sekolahStore()->activeForApi(),
                'usage' => $service->sekolahStore()->usageStats(),
            ];
        })(),
        'clear_logo_sekolah' => (function () use ($service, $input) {
            $id = trim((string) ($input['id'] ?? ''));
            if (!Auth::can('sekolah_manage')) {
                $user = Auth::user();
                $boundId = trim((string) ($user['sekolah_id'] ?? ''));
                if ($boundId === '') {
                    $boundId = (string) ($service->sekolahStore()->active()['id'] ?? '');
                }
                if ($id !== $boundId) {
                    throw new RuntimeException('FORBIDDEN');
                }
            }
            $item = $service->sekolahStore()->clearLogo($id);
            return [
                'message' => 'Logo dihapus.',
                'item' => $item,
                'sekolah' => $service->sekolahStore()->listForApi(),
                'aktif' => $service->sekolahStore()->activeForApi(),
                'usage' => $service->sekolahStore()->usageStats(),
            ];
        })(),
        'siswa_kelas' => [
            'siswa' => $service->siswaByKelas($data, $q),
            'kelas' => $q['kelas'],
        ],
        'mapel_kelas' => (function () use ($service, $data, $q) {
            $kelas = trim((string) ($q['kelas'] ?? ''));
            $codes = $kelas !== ''
                ? $service->ujianImportService()->mapelForKelas(
                    $data,
                    $kelas,
                    (string) ($q['tahun_ajaran'] ?? ''),
                    (string) ($q['semester'] ?? '')
                )
                : array_keys(UjianStore::MAPEL);
            $items = [];
            foreach ($codes as $kode) {
                $items[] = [
                    'kode' => $kode,
                    'nama' => UjianStore::MAPEL[$kode] ?? $kode,
                ];
            }
            return [
                'kelas' => $kelas,
                'mapel' => $items,
            ];
        })(),
        'ujian_templates' => [
            'templates' => $service->ujianStore()->templates(),
        ],
        'list_ujian' => [
            'ujian' => $service->ujianStore()->list($q['jenis'] !== '' ? $q['jenis'] : null),
            'templates' => $service->ujianStore()->templates(),
        ],
        'nilai_ijazah' => $service->ijazahService()->rekap($data, $q),
        'ijazah_bobot' => [
            'bobot' => $service->ijazahService()->getBobot(),
        ],
        'save_ijazah_bobot' => (function () use ($service, $input) {
            $bobot = $service->ijazahService()->saveBobot($input);
            return [
                'message' => 'Bobot nilai ijazah disimpan.',
                'bobot' => $bobot,
            ];
        })(),
        'get_ujian' => (function () use ($service, $q) {
            $id = trim((string) $q['id']);
            if ($id === '') {
                throw new InvalidArgumentException('ID ujian wajib.');
            }
            $ujian = $service->ujianStore()->get($id);
            if ($ujian === null) {
                throw new InvalidArgumentException('Ujian tidak ditemukan.');
            }
            return ['ujian' => $ujian];
        })(),
        'create_ujian' => (function () use ($service, $data, $input) {
            $kelas = trim((string) ($input['kelas'] ?? ''));
            $siswa = $input['siswa'] ?? null;
            if (!is_array($siswa) || $siswa === []) {
                $siswa = $service->siswaByKelas($data, [
                    'kelas' => $kelas,
                    'tahun_ajaran' => $input['tahun_ajaran'] ?? '',
                    'semester' => $input['semester'] ?? '',
                ]);
            }
            $input['siswa'] = $siswa;
            $row = $service->ujianStore()->create($input);
            return [
                'message' => 'Ujian berhasil dibuat dari template.',
                'ujian' => $row,
            ];
        })(),
        'update_ujian' => (function () use ($service, $input) {
            $id = trim((string) ($input['id'] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException('ID ujian wajib.');
            }
            $row = $service->ujianStore()->update($id, $input);
            return [
                'message' => 'Ujian berhasil diperbarui.',
                'ujian' => $row,
            ];
        })(),
        'save_nilai_ujian' => (function () use ($service, $input) {
            $id = trim((string) ($input['id'] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException('ID ujian wajib.');
            }
            $siswa = $input['siswa'] ?? null;
            if (!is_array($siswa)) {
                throw new InvalidArgumentException('Data nilai siswa tidak valid.');
            }
            $row = $service->ujianStore()->saveNilai($id, $siswa);
            return [
                'message' => 'Nilai ujian berhasil disimpan.',
                'ujian' => $row,
            ];
        })(),
        'delete_ujian' => (function () use ($service, $input) {
            $id = trim((string) ($input['id'] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException('ID ujian wajib.');
            }
            $service->ujianStore()->delete($id);
            return ['message' => 'Ujian berhasil dihapus.'];
        })(),
        'import_ujian_excel' => (function () use ($service, $input) {
            if (empty($_FILES['file']) && empty($_FILES['files'])) {
                throw new InvalidArgumentException('Pilih file template Excel ujian.');
            }
            $file = $_FILES['file'] ?? null;
            if ($file === null && isset($_FILES['files'])) {
                $files = $_FILES['files'];
                $file = [
                    'name' => is_array($files['name']) ? ($files['name'][0] ?? '') : $files['name'],
                    'type' => is_array($files['type']) ? ($files['type'][0] ?? '') : $files['type'],
                    'tmp_name' => is_array($files['tmp_name']) ? ($files['tmp_name'][0] ?? '') : $files['tmp_name'],
                    'error' => is_array($files['error']) ? ($files['error'][0] ?? UPLOAD_ERR_NO_FILE) : $files['error'],
                    'size' => is_array($files['size']) ? ($files['size'][0] ?? 0) : $files['size'],
                ];
            }
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload file gagal.');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new InvalidArgumentException('File upload tidak valid.');
            }

            $override = [
                'jenis' => $input['jenis'] ?? ($_POST['jenis'] ?? ''),
                'kelas' => $input['kelas'] ?? ($_POST['kelas'] ?? ''),
                'tahun_ajaran' => $input['tahun_ajaran'] ?? ($_POST['tahun_ajaran'] ?? ''),
                'semester' => $input['semester'] ?? ($_POST['semester'] ?? ''),
                'tanggal' => $input['tanggal'] ?? ($_POST['tanggal'] ?? ''),
                'penguji' => '',
                'keterangan' => $input['keterangan'] ?? ($_POST['keterangan'] ?? ''),
            ];

            // Simpan sementara dengan ekstensi asli agar parser mengenali format
            $ext = strtolower(pathinfo((string) ($file['name'] ?? 'ujian.xls'), PATHINFO_EXTENSION) ?: 'xls');
            if (!in_array($ext, ['xls', 'xlsx', 'xlsm', 'csv'], true)) {
                $ext = 'xls';
            }
            $dest = sys_get_temp_dir() . '/rdm_ujian_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($tmp, $dest)) {
                throw new RuntimeException('Gagal menyimpan file sementara.');
            }

            try {
                $result = $service->ujianImportService()->importFile($dest, $override);
            } finally {
                @unlink($dest);
            }

            $jenis = (string) ($result['meta']['jenis'] ?? ($override['jenis'] ?? ''));
            return [
                'message' => sprintf(
                    'Impor selesai: %d dibuat, %d diperbarui, %d mapel, %d siswa.',
                    count($result['created']),
                    count($result['updated']),
                    (int) $result['mapel_count'],
                    (int) $result['siswa_count']
                ),
                'result' => $result,
                'ujian' => $service->ujianStore()->list($jenis !== '' ? $jenis : null),
                'templates' => $service->ujianStore()->templates(),
            ];
        })(),
        'add_kelas' => (function () use ($service, $data, $input) {
            $nama = trim((string) ($input['nama'] ?? ''));
            $existing = $service->listKelas($data)['kelas'];
            foreach ($existing as $k) {
                if (strcasecmp(preg_replace('/\s+/', '', $k['nama']), preg_replace('/\s+/', '', $nama)) === 0) {
                    throw new InvalidArgumentException('Kelas "' . $nama . '" sudah terdaftar.');
                }
            }
            $row = $service->kelasStore()->add(
                $nama,
                (string) ($input['tingkat'] ?? ''),
                (string) ($input['tahun_ajaran'] ?? ''),
                (string) ($input['keterangan'] ?? '')
            );
            return [
                'message' => 'Kelas berhasil ditambahkan.',
                'kelas' => $row,
                'list' => $service->listKelas($data)['kelas'],
            ];
        })(),
        'delete_kelas' => (function () use ($service, $data, $input) {
            $service->kelasStore()->delete((string) ($input['id'] ?? ''));
            return [
                'message' => 'Kelas berhasil dihapus.',
                'list' => $service->listKelas($data)['kelas'],
            ];
        })(),
        'refresh' => (function () use ($service) {
            $fresh = $service->ensureData(true);
            return [
                'ok' => true,
                'imported_at' => $fresh['imported_at'],
                'source' => $fresh['source'] ?? null,
                'files' => count($fresh['source_files'] ?? []),
                'records' => count($fresh['records']),
                'students' => count($fresh['students']),
                'semesters' => count($fresh['semesters']),
            ];
        })(),
        'list_konversi' => (function () use ($service) {
            $settings = $service->konversiStore()->getSettings();
            return [
                'rules' => $settings['rules'],
                'kkm' => $settings['kkm'],
                'targets' => $settings['targets'],
                'kelompok' => KonversiStore::KELOMPOK,
            ];
        })(),
        'save_konversi' => (function () use ($service, $input) {
            $row = $service->konversiStore()->saveRule($input);
            $settings = $service->konversiStore()->getSettings();
            return [
                'message' => 'Aturan predikat disimpan.',
                'rule' => $row,
                'rules' => $settings['rules'],
                'kkm' => $settings['kkm'],
                'targets' => $settings['targets'],
            ];
        })(),
        'delete_konversi' => (function () use ($service, $input) {
            $service->konversiStore()->delete((string) ($input['id'] ?? ''));
            $settings = $service->konversiStore()->getSettings();
            return [
                'message' => 'Aturan predikat dihapus.',
                'rules' => $settings['rules'],
                'kkm' => $settings['kkm'],
                'targets' => $settings['targets'],
            ];
        })(),
        'uji_konversi' => (function () use ($service, $input) {
            $skor = (float) ($input['skor'] ?? $_GET['skor'] ?? 0);
            $hasil = $service->konversiStore()->convertPredikat($skor);
            return [
                'skor' => $skor,
                'hasil' => $hasil,
            ];
        })(),
        'save_konversi_settings' => (function () use ($service, $input) {
            $settings = $service->konversiStore()->saveSettings($input);
            return [
                'message' => 'Pengaturan mesin konversi disimpan.',
                'kkm' => $settings['kkm'],
                'targets' => $settings['targets'],
                'rules' => $settings['rules'],
            ];
        })(),
        'run_konversi' => (function () use ($service, $input) {
            $siswa = $input['siswa'] ?? [];
            if (!is_array($siswa) || $siswa === []) {
                throw new InvalidArgumentException('Data siswa kosong.');
            }
            $result = $service->konversiService()->convert($siswa, $input);
            return [
                'message' => 'Konversi selesai: ' . ($result['stats']['terisi'] ?? 0) . ' nilai.',
                'result' => $result,
            ];
        })(),
        'import_konversi' => (function () use ($service, $input) {
            if (empty($_FILES['file']) && empty($_FILES['files'])) {
                throw new InvalidArgumentException('Pilih file template konversi (.xls / .xlsx).');
            }
            $file = $_FILES['file'] ?? null;
            if ($file === null && isset($_FILES['files'])) {
                $files = $_FILES['files'];
                $file = [
                    'name' => is_array($files['name']) ? ($files['name'][0] ?? '') : $files['name'],
                    'type' => is_array($files['type']) ? ($files['type'][0] ?? '') : $files['type'],
                    'tmp_name' => is_array($files['tmp_name']) ? ($files['tmp_name'][0] ?? '') : $files['tmp_name'],
                    'error' => is_array($files['error']) ? ($files['error'][0] ?? UPLOAD_ERR_NO_FILE) : $files['error'],
                    'size' => is_array($files['size']) ? ($files['size'][0] ?? 0) : $files['size'],
                ];
            }
            if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
                throw new InvalidArgumentException('Upload file gagal.');
            }
            $tmp = (string) ($file['tmp_name'] ?? '');
            if ($tmp === '' || !is_uploaded_file($tmp)) {
                throw new InvalidArgumentException('File upload tidak valid.');
            }
            $ext = strtolower(pathinfo((string) ($file['name'] ?? 'konversi.xls'), PATHINFO_EXTENSION) ?: 'xls');
            if (!in_array($ext, ['xls', 'xlsx', 'xlsm', 'xml'], true)) {
                $ext = 'xls';
            }
            $dest = sys_get_temp_dir() . '/rdm_konversi_' . bin2hex(random_bytes(4)) . '.' . $ext;
            if (!move_uploaded_file($tmp, $dest)) {
                throw new RuntimeException('Gagal menyimpan file sementara.');
            }
            try {
                $parsed = $service->konversiService()->parseUpload($dest);
                $meta = array_merge($parsed['meta'], [
                    'kelas' => trim((string) ($input['kelas'] ?? $parsed['meta']['kelas'] ?? '')),
                    'tahun_ajaran' => trim((string) ($input['tahun_ajaran'] ?? $parsed['meta']['tahun_ajaran'] ?? '')),
                    'mapel' => trim((string) ($input['mapel'] ?? $parsed['meta']['mapel'] ?? '')),
                ]);
                if (isset($input['kkm']) && $input['kkm'] !== '') {
                    $meta['kkm'] = (float) $input['kkm'];
                } elseif (($parsed['meta']['kkm'] ?? null) !== null) {
                    $meta['kkm'] = $parsed['meta']['kkm'];
                }
                if (isset($input['targets']) && is_array($input['targets'])) {
                    $meta['targets'] = $input['targets'];
                }
                $result = $service->konversiService()->convert($parsed['siswa'], $meta);
            } finally {
                @unlink($dest);
            }
            return [
                'message' => 'Impor & konversi selesai: ' . ($result['stats']['terisi'] ?? 0) . ' nilai.',
                'parsed' => $parsed,
                'result' => $result,
            ];
        })(),
        'list_rapor_nilai' => [
            'entries' => $service->raporNilaiStore()->list($q['jenis'] !== '' ? $q['jenis'] : null),
            'mapel' => $service->raporNilaiStore()->mapelList(),
            'jenis_label' => RaporNilaiStore::JENIS_LABEL,
        ],
        'get_rapor_nilai' => (function () use ($service, $q) {
            $id = trim((string) $q['id']);
            if ($id === '') {
                throw new InvalidArgumentException('ID wajib.');
            }
            $entry = $service->raporNilaiStore()->get($id);
            if ($entry === null) {
                throw new InvalidArgumentException('Data tidak ditemukan.');
            }
            return ['entry' => $entry];
        })(),
        'create_rapor_nilai' => (function () use ($service, $data, $input) {
            $kelas = trim((string) ($input['kelas'] ?? ''));
            $siswa = $input['siswa'] ?? null;
            if (!is_array($siswa) || $siswa === []) {
                $siswa = $service->siswaByKelas($data, [
                    'kelas' => $kelas,
                    'tahun_ajaran' => $input['tahun_ajaran'] ?? '',
                    'semester' => $input['semester'] ?? '',
                ]);
            }
            $input['siswa'] = $siswa;
            $row = $service->raporNilaiStore()->create($input);
            return [
                'message' => 'Data ' . ($row['jenis_label'] ?? 'nilai') . ' dibuat.',
                'entry' => $row,
                'entries' => $service->raporNilaiStore()->list($row['jenis'] ?? null),
            ];
        })(),
        'update_rapor_nilai' => (function () use ($service, $input) {
            $id = trim((string) ($input['id'] ?? ''));
            if ($id === '') {
                throw new InvalidArgumentException('ID wajib.');
            }
            $row = $service->raporNilaiStore()->update($id, $input);
            return [
                'message' => 'Nilai berhasil disimpan.',
                'entry' => $row,
            ];
        })(),
        'delete_rapor_nilai' => (function () use ($service, $input) {
            $jenis = trim((string) ($input['jenis'] ?? ''));
            $service->raporNilaiStore()->delete((string) ($input['id'] ?? ''));
            return [
                'message' => 'Data dihapus.',
                'entries' => $service->raporNilaiStore()->list($jenis !== '' ? $jenis : null),
            ];
        })(),
        default => throw new InvalidArgumentException('Aksi tidak dikenal: ' . $action),
    };

    echo json_encode(['ok' => true, 'data' => $payload], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    $msg = $e->getMessage();
    if ($msg === 'UNAUTHORIZED') {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Silakan login terlebih dahulu.', 'code' => 'UNAUTHORIZED'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($msg === 'FORBIDDEN') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Anda tidak berwenang untuk aksi ini.', 'code' => 'FORBIDDEN'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($msg === 'CSRF') {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Sesi tidak valid. Muat ulang halaman lalu coba lagi.', 'code' => 'CSRF'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($msg === 'METHOD') {
        http_response_code(405);
        echo json_encode(['ok' => false, 'error' => 'Metode HTTP tidak diizinkan.', 'code' => 'METHOD'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $code = $e instanceof InvalidArgumentException ? 400 : 500;
    http_response_code($code);
    $safe = $msg;
    if ($code === 500) {
        $looksInternal = str_contains($msg, '/opt/')
            || str_contains($msg, '\\')
            || str_contains(strtolower($msg), 'sql')
            || str_contains(strtolower($msg), 'stack');
        if ($looksInternal || !($e instanceof RuntimeException)) {
            $safe = 'Terjadi kesalahan server.';
        }
    }
    echo json_encode([
        'ok' => false,
        'error' => $safe,
    ], JSON_UNESCAPED_UNICODE);
}
