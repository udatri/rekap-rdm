<?php

declare(strict_types=1);

require_once __DIR__ . '/lib/Auth.php';
require_once __DIR__ . '/lib/Security.php';
require_once __DIR__ . '/lib/Config.php';
require_once __DIR__ . '/lib/SekolahStore.php';

Auth::startSession();
Security::sendHeaders(false);

if (Auth::check()) {
    header('Location: index.php');
    exit;
}

$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $user = trim((string) ($_POST['username'] ?? ''));
    $pass = (string) ($_POST['password'] ?? '');
    try {
        Auth::login($user, $pass);
        $next = (string) ($_POST['next'] ?? $_GET['next'] ?? 'index.php');
        if ($next === '' || !preg_match('#^[a-zA-Z0-9_./?-]+$#', $next) || str_contains($next, '..')) {
            $next = 'index.php';
        }
        header('Location: ' . $next);
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$store = new SekolahStore();
$sekolah = 'Rekap RDM';
$available = [];
$usage = [
    'total_slots' => 0,
    'pernah' => 0,
    'sedang_pakai' => 0,
    'siap' => 0,
    'history' => [],
];
try {
    // Branding login = Sekolah 1 (utama), bukan sekolah sesi terakhir
    $utama = $store->get('SCH01') ?? $store->active();
    $sekolah = (string) (($utama['nama'] ?? '') !== '' ? $utama['nama'] : $sekolah);
    $available = $store->availableForLogin(5);
    $usage = $store->usageStats();
} catch (Throwable) {
}

$next = (string) ($_GET['next'] ?? 'index.php');
$esc = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="id">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Login — <?= $esc($sekolah) ?></title>
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:ital,opsz,wght@0,9..40,400;0,9..40,500;0,9..40,600;0,9..40,700&family=Source+Serif+4:opsz,wght@8..60,600;8..60,700&display=swap" rel="stylesheet" />
  <link rel="stylesheet" href="assets/style.css" />
  <style>
    body.login-page {
      min-height: 100vh;
      display: grid;
      place-items: center;
      margin: 0;
      padding: 1.5rem 0;
      background:
        radial-gradient(1200px 600px at 10% -10%, #e7f3ec 0%, transparent 55%),
        radial-gradient(900px 500px at 100% 0%, #f3e4d2 0%, transparent 50%),
        #f6f3ee;
    }
    .login-card {
      width: min(420px, calc(100vw - 2rem));
      background: #fff;
      border: 1px solid #e2d6c6;
      border-radius: 16px;
      padding: 1.75rem 1.5rem 1.5rem;
      box-shadow: 0 18px 40px rgba(60, 40, 20, 0.08);
    }
    .login-card h1 {
      margin: 0.15rem 0 0.35rem;
      font-family: "Source Serif 4", Georgia, serif;
      font-size: 1.55rem;
      color: #0f5c45;
    }
    .login-card .sub {
      margin: 0 0 1.25rem;
      color: #6b5c4c;
      font-size: 0.92rem;
    }
    .login-card label {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      margin-bottom: 0.85rem;
      font-size: 0.88rem;
      color: #4a3d32;
    }
    .login-card input {
      border: 1px solid #d7cbb9;
      border-radius: 8px;
      padding: 0.65rem 0.75rem;
      font: inherit;
    }
    .login-card .btn {
      width: 100%;
      justify-content: center;
      margin-top: 0.35rem;
    }
    .login-credit {
      margin: 1rem 0 0;
      text-align: center;
      font-size: 0.82rem;
      color: #6b5c4c;
    }
    .login-credit strong {
      color: #0f5c45;
      letter-spacing: 0.04em;
    }
    .login-error {
      background: #fde8e8;
      color: #8a1f1f;
      border: 1px solid #f0c2c2;
      border-radius: 8px;
      padding: 0.65rem 0.75rem;
      margin-bottom: 0.85rem;
      font-size: 0.9rem;
    }
    .login-recent {
      margin: 0 0 1.15rem;
      padding: 0.75rem 0.85rem;
      border: 1px solid #e2d6c6;
      border-radius: 12px;
      background: linear-gradient(180deg, #fbf8f3 0%, #f7f2ea 100%);
    }
    .login-recent h2 {
      margin: 0 0 0.55rem;
      font-size: 0.78rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #0f5c45;
    }
    .login-recent-list {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 0.4rem;
    }
    .login-recent-list button {
      width: 100%;
      display: flex;
      align-items: center;
      justify-content: space-between;
      gap: 0.75rem;
      text-align: left;
      border: 1px solid #e5dacb;
      border-radius: 8px;
      background: #fff;
      padding: 0.55rem 0.7rem;
      cursor: pointer;
      font: inherit;
      color: #3d3228;
      transition: border-color 0.15s ease, background 0.15s ease;
    }
    .login-recent-list button:hover,
    .login-recent-list button:focus-visible {
      border-color: #0f5c45;
      background: #f3faf6;
      outline: none;
    }
    .login-recent-list .nama {
      font-size: 0.88rem;
      font-weight: 600;
      line-height: 1.25;
    }
    .login-recent-list .meta {
      font-size: 0.72rem;
      color: #7a6a58;
      margin-top: 0.12rem;
    }
    .login-recent-list .akun {
      flex-shrink: 0;
      font-size: 0.75rem;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      color: #0f5c45;
      background: #e7f3ec;
      padding: 0.2rem 0.45rem;
      border-radius: 999px;
    }
    .login-usage {
      margin: 0 0 1.15rem;
      padding: 0.75rem 0.85rem;
      border: 1px solid #e2d6c6;
      border-radius: 12px;
      background: #fff;
    }
    .login-usage h2 {
      margin: 0 0 0.55rem;
      font-size: 0.78rem;
      font-weight: 600;
      letter-spacing: 0.04em;
      text-transform: uppercase;
      color: #0f5c45;
    }
    .login-usage-stats {
      display: flex;
      flex-wrap: wrap;
      gap: 0.4rem;
      margin: 0 0 0.65rem;
    }
    .login-usage-stats span {
      flex: 1 1 5.5rem;
      background: #f7f2ea;
      border: 1px solid #e8ddd0;
      border-radius: 8px;
      padding: 0.4rem 0.5rem;
      font-size: 0.72rem;
      color: #7a6a58;
      line-height: 1.25;
    }
    .login-usage-stats strong {
      display: block;
      color: #3d3228;
      font-size: 1.05rem;
      font-variant-numeric: tabular-nums;
    }
    .login-usage-history {
      list-style: none;
      margin: 0;
      padding: 0;
      display: flex;
      flex-direction: column;
      gap: 0.3rem;
    }
    .login-usage-history li {
      display: flex;
      justify-content: space-between;
      gap: 0.5rem;
      align-items: baseline;
      font-size: 0.8rem;
      color: #3d3228;
      padding: 0.35rem 0;
      border-top: 1px solid #f0e8dc;
    }
    .login-usage-history li:first-child {
      border-top: 0;
      padding-top: 0;
    }
    .login-usage-history .meta {
      flex-shrink: 0;
      font-size: 0.72rem;
      color: #7a6a58;
    }
  </style>
</head>
<body class="login-page">
  <form class="login-card" method="post" autocomplete="username" id="loginForm">
    <p class="brand-kicker" style="margin:0;color:#0f5c45;font-weight:600;font-size:0.8rem;letter-spacing:.04em;text-transform:uppercase">Rekap Nilai RDM</p>
    <h1><?= $esc($sekolah) ?></h1>
    <p class="sub">Masuk dengan akun yang diberikan administrator.</p>

    <?php if ($error !== ''): ?>
      <div class="login-error"><?= $esc($error) ?></div>
    <?php endif; ?>

    <?php
      $fmtAt = static function (string $iso): string {
          $t = $iso !== '' ? strtotime($iso) : false;
          return $t ? date('d/m/Y', $t) : '—';
      };
      $historyPreview = array_slice($usage['history'] ?? [], 0, 5);
    ?>
    <div class="login-usage">
      <h2>Statistik pemakaian</h2>
      <div class="login-usage-stats">
        <span><strong><?= (int) ($usage['pernah'] ?? 0) ?></strong>pernah memakai</span>
        <span><strong><?= (int) ($usage['sedang_pakai'] ?? 0) ?></strong>sedang dipakai</span>
        <span><strong><?= (int) ($usage['siap'] ?? 0) ?></strong>siap dipakai</span>
      </div>
      <?php if ($historyPreview !== []): ?>
        <ul class="login-usage-history">
          <?php foreach ($historyPreview as $h): ?>
            <li>
              <span><?= $esc((string) ($h['nama'] ?? $h['id'] ?? '')) ?></span>
              <span class="meta"><?= $esc($fmtAt((string) ($h['last_at'] ?? ''))) ?><?php if ((int) ($h['times'] ?? 1) > 1): ?> · <?= (int) $h['times'] ?>×<?php endif; ?></span>
            </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p style="margin:0;font-size:0.78rem;color:#7a6a58;line-height:1.35">Belum ada sekolah (selain Sekolah 1) yang tercatat memakai aplikasi.</p>
      <?php endif; ?>
    </div>

    <?php if ($available !== []): ?>
      <?php
        $nAvail = count($available);
        $judulAvail = $nAvail === 1
          ? '1 sekolah siap dipakai'
          : ($nAvail . ' sekolah siap dipakai');
      ?>
      <div class="login-recent">
        <h2><?= $esc($judulAvail) ?></h2>
        <p style="margin:0 0 0.55rem;font-size:0.78rem;color:#7a6a58;line-height:1.35">Pilih sekolah yang belum terpakai, lalu masuk dengan akun di samping. Setelah dipakai, data Excel &amp; nama otomatis reset dalam 7 hari (kecuali Sekolah 1). Sekolah terpakai tetap bisa login manual.</p>
        <ul class="login-recent-list">
          <?php foreach ($available as $r): ?>
            <li>
              <button
                type="button"
                class="js-pick-school"
                data-username="<?= $esc((string) ($r['admin_username'] ?? '')) ?>"
                title="Isi username admin sekolah ini"
              >
                <span>
                  <span class="nama"><?= $esc((string) ($r['nama'] ?? '')) ?></span>
                  <span class="meta"><?= $esc((string) ($r['id'] ?? '')) ?></span>
                </span>
                <?php if (($r['admin_username'] ?? '') !== ''): ?>
                  <span class="akun"><?= $esc((string) $r['admin_username']) ?></span>
                <?php endif; ?>
              </button>
            </li>
          <?php endforeach; ?>
        </ul>
      </div>
    <?php else: ?>
      <div class="login-recent">
        <h2>Sekolah siap dipakai</h2>
        <p style="margin:0;font-size:0.82rem;color:#7a6a58;line-height:1.4">Semua slot (selain Sekolah 1) sudah terpakai. Sekolah terpakai tetap bisa dimasuki dengan username <code>adminN</code> dan password-nya.</p>
      </div>
    <?php endif; ?>

    <input type="hidden" name="next" value="<?= $esc($next) ?>" />
    <label>
      <span>Username</span>
      <input type="text" name="username" id="username" required autofocus maxlength="40" placeholder="username" />
    </label>
    <label>
      <span>Password</span>
      <input type="password" name="password" id="password" required maxlength="100" placeholder="••••••••" />
    </label>
    <button type="submit" class="btn primary">Masuk</button>
    <p class="login-credit">Aplikasi ini dibuat oleh <strong>DIAL</strong></p>
  </form>
  <script>
    document.querySelectorAll('.js-pick-school').forEach((btn) => {
      btn.addEventListener('click', () => {
        const user = btn.getAttribute('data-username') || '';
        const userEl = document.getElementById('username');
        const passEl = document.getElementById('password');
        if (userEl && user) {
          userEl.value = user;
          userEl.focus();
        }
        if (passEl) passEl.focus();
      });
    });
  </script>
</body>
</html>
