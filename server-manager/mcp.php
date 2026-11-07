<?php
declare(strict_types=1);

/**
 * Pluschat Server Manager — stateless MCP endpoint for PHP 8.1+.
 * Install in /www/xn--80avljg2a1c.xn--p1ai/pluschat-manager/
 * Keep config.php private and restrict this endpoint to HTTPS.
 */
$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    exit('Server Manager is not configured. Create config.php from config.example.php.');
}
$config = require $configFile;
$root = isset($config['root']) ? realpath((string)$config['root']) : false;
$token = (string)($config['token'] ?? '');
if ($root === false || !is_dir($root) || strlen($token) < 40) {
    http_response_code(503);
    exit('Invalid Server Manager configuration.');
}
$storage = __DIR__ . '/backups';
if (!is_dir($storage) && !mkdir($storage, 0700, true) && !is_dir($storage)) {
    http_response_code(500);
    exit('Cannot initialize private backup storage.');
}
@chmod($storage, 0700);

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');

function rpc(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}
function rpcError($id, int $code, string $message): never {
    rpc(['jsonrpc'=>'2.0', 'id'=>$id, 'error'=>['code'=>$code, 'message'=>$message]]);
}
function safePath(string $relative, string $root, bool $mustExist = false): string {
    if ($relative === '' || str_contains($relative, "\0") || str_contains($relative, '\\')) {
        throw new RuntimeException('Invalid relative path.');
    }
    $parts = explode('/', $relative);
    foreach ($parts as $part) {
        if ($part === '' || $part === '.' || $part === '..' || str_starts_with($part, '.pluschat-manager')) {
            throw new RuntimeException('Path is not allowed.');
        }
    }
    $candidate = $root . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $parts);
    $cursor = $root;
    foreach ($parts as $i => $part) {
        $cursor .= DIRECTORY_SEPARATOR . $part;
        if (is_link($cursor)) throw new RuntimeException('Symbolic links are not allowed.');
        if ($i < count($parts)-1 && file_exists($cursor) && !is_dir($cursor)) {
            throw new RuntimeException('A parent path component is not a directory.');
        }
    }
    if ($mustExist && !file_exists($candidate)) throw new RuntimeException('Path does not exist.');
    $parent = realpath(dirname($candidate));
    if ($parent === false || ($parent !== $root && !str_starts_with($parent, $root . DIRECTORY_SEPARATOR))) {
        throw new RuntimeException('Path escapes the allowed directory.');
    }
    if (file_exists($candidate)) {
        $real = realpath($candidate);
        if ($real === false || ($real !== $root && !str_starts_with($real, $root . DIRECTORY_SEPARATOR))) {
            throw new RuntimeException('Path escapes the allowed directory.');
        }
    }
    return $candidate;
}
function backupFile(string $path, string $root, string $storage): ?string {
    if (!is_file($path)) return null;
    $rel = ltrim(substr($path, strlen($root)), DIRECTORY_SEPARATOR);
    $name = gmdate('Ymd-His') . '-' . bin2hex(random_bytes(4)) . '-' . str_replace(DIRECTORY_SEPARATOR, '_', $rel);
    $dest = $storage . DIRECTORY_SEPARATOR . $name . '.bak';
    if (!copy($path, $dest)) throw new RuntimeException('Could not create a backup; operation cancelled.');
    @chmod($dest, 0600);
    return $name . '.bak';
}
function toolResult($value): array {
    return ['content'=>[['type'=>'text', 'text'=>is_string($value) ? $value : json_encode($value, JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE)]], 'isError'=>false];
}

if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    rpc(['error'=>'POST required'], 405);
}
$auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
if (!preg_match('/^Bearer\s+(.+)$/i', $auth, $m) || !hash_equals($token, trim($m[1]))) {
    rpc(['error'=>'Unauthorized'], 401);
}
$raw = file_get_contents('php://input');
if ($raw === false || strlen($raw) > 3_000_000) rpc(['error'=>'Invalid request size'], 413);
$request = json_decode($raw, true);
if (!is_array($request) || ($request['jsonrpc'] ?? '') !== '2.0' || !array_key_exists('method', $request)) {
    rpcError($request['id'] ?? null, -32600, 'Invalid JSON-RPC request.');
}
$id = $request['id'] ?? null;
$method = (string)$request['method'];
if ($method === 'notifications/initialized') { http_response_code(202); exit; }
if ($method === 'ping') rpc(['jsonrpc'=>'2.0','id'=>$id,'result'=>(object)[]]);
if ($method === 'initialize') {
    rpc(['jsonrpc'=>'2.0','id'=>$id,'result'=>[
        'protocolVersion'=>'2025-03-26',
        'capabilities'=>['tools'=>new stdClass()],
        'serverInfo'=>['name'=>'pluschat-server-manager','version'=>'1.0.0'],
        'instructions'=>'Manage files only inside the configured Pluschat site directory. Destructive actions create backups; ask the user before deletion or bulk changes.'
    ]]);
}
if ($method === 'tools/list') {
    $tools = [
      ['name'=>'list_files','description'=>'List files and folders in a site directory. Paths are relative to the configured root; use "." for root.','inputSchema'=>['type'=>'object','properties'=>['path'=>['type'=>'string']],'required'=>['path']]],
      ['name'=>'read_file','description'=>'Read a UTF-8 text file up to 1 MB.','inputSchema'=>['type'=>'object','properties'=>['path'=>['type'=>'string']],'required'=>['path']]],
      ['name'=>'write_file','description'=>'Create or replace a UTF-8 text file up to 2 MB. Existing files are backed up automatically.','inputSchema'=>['type'=>'object','properties'=>['path'=>['type'=>'string'],'content'=>['type'=>'string']],'required'=>['path','content']]],
      ['name'=>'create_directory','description'=>'Create a directory inside the allowed site root.','inputSchema'=>['type'=>'object','properties'=>['path'=>['type'=>'string']],'required'=>['path']]],
      ['name'=>'delete_file','description'=>'Delete a file after creating a private backup. Directories and symlinks cannot be deleted.','inputSchema'=>['type'=>'object','properties'=>['path'=>['type'=>'string']],'required'=>['path']]],
    ];
    rpc(['jsonrpc'=>'2.0','id'=>$id,'result'=>['tools'=>$tools]]);
}
if ($method !== 'tools/call') rpcError($id, -32601, 'Method not found.');
$params = $request['params'] ?? [];
$name = (string)($params['name'] ?? '');
$args = $params['arguments'] ?? [];
try {
    if (!is_array($args)) throw new RuntimeException('Invalid tool arguments.');
    switch ($name) {
      case 'list_files':
        $rel = (string)($args['path'] ?? '.');
        if ($rel === '.') $dir = $root;
        else $dir = safePath($rel, $root, true);
        if (!is_dir($dir)) throw new RuntimeException('Path is not a directory.');
        $items = [];
        foreach (new DirectoryIterator($dir) as $item) {
            if ($item->isDot() || str_starts_with($item->getFilename(), '.pluschat-manager')) continue;
            if ($item->isLink()) continue;
            $items[] = ['name'=>$item->getFilename(), 'type'=>$item->isDir()?'directory':'file', 'size'=>$item->isFile()?$item->getSize():null, 'modified'=>gmdate('c',$item->getMTime())];
        }
        usort($items, fn($a,$b) => [$a['type'] !== 'directory', strtolower($a['name'])] <=> [$b['type'] !== 'directory', strtolower($b['name'])]);
        $out = ['path'=>$rel, 'items'=>$items];
        break;
      case 'read_file':
        $path = safePath((string)($args['path'] ?? ''), $root, true);
        if (!is_file($path) || filesize($path) > 1_048_576) throw new RuntimeException('Only regular files up to 1 MB can be read.');
        $data = file_get_contents($path);
        if ($data === false || !preg_match('//u', $data)) throw new RuntimeException('File is not valid UTF-8 text.');
        $out = $data;
        break;
      case 'write_file':
        $rel = (string)($args['path'] ?? '');
        $data = (string)($args['content'] ?? '');
        if (strlen($data) > 2_097_152 || !preg_match('//u', $data)) throw new RuntimeException('Content must be valid UTF-8 and no larger than 2 MB.');
        $path = safePath($rel, $root);
        if (file_exists($path) && !is_file($path)) throw new RuntimeException('Target exists and is not a regular file.');
        if (is_file($path)) $backup = backupFile($path, $root, $storage);
        $written = file_put_contents($path, $data, LOCK_EX);
        if ($written === false) throw new RuntimeException('Could not write file.');
        $out = ['written'=>$written, 'backup'=>$backup ?? null];
        break;
      case 'create_directory':
        $path = safePath((string)($args['path'] ?? ''), $root);
        if (file_exists($path)) throw new RuntimeException('Path already exists.');
        if (!mkdir($path, 0750, true)) throw new RuntimeException('Could not create directory.');
        $out = ['created'=>true];
        break;
      case 'delete_file':
        $path = safePath((string)($args['path'] ?? ''), $root, true);
        if (!is_file($path) || is_link($path)) throw new RuntimeException('Only regular files can be deleted; directories are not supported.');
        $backup = backupFile($path, $root, $storage);
        if (!unlink($path)) throw new RuntimeException('Could not delete file; backup retained.');
        $out = ['deleted'=>true, 'backup'=>$backup];
        break;
      default:
        rpc(['jsonrpc'=>'2.0','id'=>$id,'result'=>['content'=>[['type'=>'text','text'=>'Unknown tool.']],'isError'=>true]]);
    }
    rpc(['jsonrpc'=>'2.0','id'=>$id,'result'=>toolResult($out)]);
} catch (Throwable $e) {
    rpc(['jsonrpc'=>'2.0','id'=>$id,'result'=>['content'=>[['type'=>'text','text'=>$e->getMessage()]],'isError'=>true]]);
}
