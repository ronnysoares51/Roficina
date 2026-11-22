<?php
declare(strict_types=1);

session_start(['cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true]);
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
header("Content-Security-Policy: default-src 'self'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
$_SESSION['csrf'] ??= bin2hex(random_bytes(32));

function esc(mixed $value): string {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function brandInitial(string $brand): string {
    return preg_match('/^./us', $brand, $match) ? $match[0] : 'R';
}

function field(string $name): string {
    return is_string($_POST[$name] ?? null) ? trim($_POST[$name]) : '';
}

function redirect(string $query = ''): never {
    header('Location: ./' . $query, true, 303);
    exit;
}

function textField(string $name, int $max, bool $required = true): string {
    $value = field($name);
    if (($required && $value === '') || strlen($value) > $max) {
        throw new DomainException('Confira os campos preenchidos e tente novamente.');
    }
    return $value;
}

try {
    $dataDir = getenv('ROFICINA_DATA_DIR') ?: dirname(__DIR__, 2) . '/roficina-data';
    umask(0077);
    if (!is_dir($dataDir) && !mkdir($dataDir, 0700, true) && !is_dir($dataDir)) {
        throw new RuntimeException('Diretório de dados indisponível.');
    }
    $db = new PDO('sqlite:' . $dataDir . '/oficinas.sqlite3', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout = 4000');
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec("CREATE TABLE IF NOT EXISTS workshops (
        id INTEGER PRIMARY KEY, email TEXT NOT NULL UNIQUE, pass TEXT NOT NULL, name TEXT NOT NULL,
        logo BLOB, logo_type TEXT
    );
    CREATE TABLE IF NOT EXISTS orders (
        id INTEGER PRIMARY KEY, workshop_id INTEGER NOT NULL REFERENCES workshops(id),
        customer TEXT NOT NULL, phone TEXT NOT NULL, vehicle_type TEXT NOT NULL, vehicle TEXT NOT NULL,
        plate TEXT NOT NULL, diagnosis TEXT NOT NULL, parts TEXT NOT NULL, labor INTEGER NOT NULL,
        total INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'Orçamento', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $error) {
    error_log('R Oficina: ' . $error->getMessage());
    http_response_code(503);
    exit('R Oficina está temporariamente indisponível.');
}

$workshop = null;
if (isset($_SESSION['workshop_id'])) {
    $stmt = $db->prepare('SELECT id, name, email, logo_type FROM workshops WHERE id = ?');
    $stmt->execute([$_SESSION['workshop_id']]);
    $workshop = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

if (isset($_GET['logo'])) {
    if (!$workshop || !$workshop['logo_type']) {
        http_response_code(404);
        exit;
    }
    $stmt = $db->prepare('SELECT logo, logo_type FROM workshops WHERE id = ?');
    $stmt->execute([$workshop['id']]);
    $logo = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$logo || !$logo['logo']) {
        http_response_code(404);
        exit;
    }
    header('Content-Type: ' . $logo['logo_type']);
    echo $logo['logo'];
    exit;
}

function money(int $cents): string {
    return 'R$ ' . number_format($cents / 100, 2, ',', '.');
}

function cents(string $amount): int {
    if (!preg_match('/^\d{1,7}(?:[.,]\d{1,2})?$/D', $amount)) {
        throw new DomainException('Informe os valores no formato 123,45.');
    }
    [$whole, $fraction] = array_pad(explode('.', str_replace(',', '.', $amount), 2), 2, '');
    return ((int) $whole * 100) + (int) str_pad($fraction, 2, '0');
}

$statuses = ['Orçamento', 'Aprovado', 'Em serviço', 'Entregue', 'Recusado'];
$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
$errorMessage = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($_SESSION['csrf'], field('csrf'))) {
        http_response_code(403);
        exit('Sessão expirada. Atualize a página e tente novamente.');
    }
    $action = field('action');
    try {
        if ($action === 'register' && !$workshop) {
            $email = strtolower(textField('email', 254));
            $password = $_POST['password'] ?? '';
            $name = textField('name', 120);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL) || !is_string($password) || strlen($password) < 10 || strlen($password) > 72 || str_contains($password, "\0")) {
                throw new DomainException('Use um e-mail válido e uma senha de 10 a 72 bytes.');
            }
            $stmt = $db->prepare('INSERT INTO workshops (email, pass, name) VALUES (?, ?, ?) ON CONFLICT(email) DO NOTHING');
            $stmt->execute([$email, password_hash($password, PASSWORD_DEFAULT), $name]);
            if (!$stmt->rowCount()) {
                throw new DomainException('Esse e-mail já tem cadastro. Entre na sua oficina.');
            }
            session_regenerate_id(true);
            $_SESSION = ['workshop_id' => (int) $db->lastInsertId(), 'csrf' => bin2hex(random_bytes(32)), 'flash' => 'Oficina criada. Você já pode personalizar a logo.'];
            redirect('?pagina=identidade');
        }
        if ($action === 'login' && !$workshop) {
            $now = time();
            $attempts = array_values(array_filter($_SESSION['attempts'] ?? [], fn ($at) => $at > $now - 300));
            if (count($attempts) >= 8) {
                throw new DomainException('Muitas tentativas. Aguarde alguns minutos.');
            }
            $attempts[] = $now;
            $_SESSION['attempts'] = $attempts;
            $stmt = $db->prepare('SELECT id, pass FROM workshops WHERE email = ?');
            $stmt->execute([strtolower(field('email'))]);
            $account = $stmt->fetch(PDO::FETCH_ASSOC);
            $password = $_POST['password'] ?? '';
            $hash = $account['pass'] ?? '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi.';
            if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0") || !password_verify($password, $hash) || !$account) {
                throw new DomainException('E-mail ou senha incorretos.');
            }
            session_regenerate_id(true);
            $_SESSION = ['workshop_id' => (int) $account['id'], 'csrf' => bin2hex(random_bytes(32))];
            redirect();
        }
        if (!$workshop) {
            throw new DomainException('Entre na sua oficina para continuar.');
        }
        if ($action === 'logout') {
            $_SESSION = [];
            session_regenerate_id(true);
            redirect();
        }
        if ($action === 'branding') {
            $name = textField('name', 120);
            $upload = $_FILES['logo'] ?? null;
            if ($upload && $upload['error'] !== UPLOAD_ERR_NO_FILE) {
                if ($upload['error'] !== UPLOAD_ERR_OK || $upload['size'] > 2 * 1024 * 1024 || !is_uploaded_file($upload['tmp_name'])) {
                    throw new DomainException('Envie uma imagem com até 2 MB.');
                }
                $bytes = file_get_contents($upload['tmp_name']);
                $info = $bytes ? @getimagesizefromstring($bytes) : false;
                if (!$info || !in_array($info['mime'], ['image/png', 'image/jpeg', 'image/webp'], true) || max($info[0], $info[1]) > 4096) {
                    throw new DomainException('Use PNG, JPG ou WebP com até 4.096 pixels de largura e altura.');
                }
                $stmt = $db->prepare('UPDATE workshops SET name = ?, logo = ?, logo_type = ? WHERE id = ?');
                $stmt->bindValue(1, $name);
                $stmt->bindValue(2, $bytes, PDO::PARAM_LOB);
                $stmt->bindValue(3, $info['mime']);
                $stmt->bindValue(4, $workshop['id'], PDO::PARAM_INT);
                $stmt->execute();
            } elseif (field('remove_logo') === '1') {
                $stmt = $db->prepare('UPDATE workshops SET name = ?, logo = NULL, logo_type = NULL WHERE id = ?');
                $stmt->execute([$name, $workshop['id']]);
            } else {
                $stmt = $db->prepare('UPDATE workshops SET name = ? WHERE id = ?');
                $stmt->execute([$name, $workshop['id']]);
            }
            $_SESSION['flash'] = 'Identidade visual salva.';
            redirect('?pagina=identidade');
        }
        if ($action === 'new_order') {
            $customer = textField('customer', 160);
            $phone = preg_replace('/\D/', '', textField('phone', 32));
            if (strlen($phone) === 10 || strlen($phone) === 11) {
                $phone = '55' . $phone;
            }
            if (!preg_match('/^55\d{10,11}$/D', $phone)) {
                throw new DomainException('Informe um WhatsApp brasileiro com DDD.');
            }
            $type = field('vehicle_type');
            if (!in_array($type, ['Carro', 'Moto'], true)) {
                throw new DomainException('Selecione carro ou moto.');
            }
            $parts = [];
            $total = $labor = cents(field('labor'));
            foreach (preg_split('/\R/', textField('parts', 5000, false)) as $line) {
                if (trim($line) === '') {
                    continue;
                }
                $details = array_map('trim', explode('|', $line));
                if (count($parts) >= 25 || count($details) !== 3 || $details[0] === '' || !preg_match('/^[1-9]\d{0,2}$/D', $details[1])) {
                    throw new DomainException('Use uma linha por peça, no formato nome | quantidade | preço unitário.');
                }
                [$part, $qty, $price] = $details;
                $unit = cents($price);
                $total += (int) $qty * $unit;
                $parts[] = [$part, (int) $qty, $unit];
            }
            $stmt = $db->prepare('INSERT INTO orders (workshop_id, customer, phone, vehicle_type, vehicle, plate, diagnosis, parts, labor, total) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$workshop['id'], $customer, $phone, $type, textField('vehicle', 160), strtoupper(textField('plate', 20, false)), textField('diagnosis', 2400), json_encode($parts, JSON_THROW_ON_ERROR), $labor, $total]);
            $_SESSION['flash'] = 'Ordem de serviço registrada.';
            redirect();
        }
        if ($action === 'status' && in_array(field('status'), $statuses, true)) {
            $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ? AND workshop_id = ?');
            $stmt->execute([field('status'), (int) field('id'), $workshop['id']]);
            redirect();
        }
        throw new DomainException('Ação indisponível.');
    } catch (DomainException $error) {
        $errorMessage = $error->getMessage();
    } catch (Throwable $error) {
        error_log('R Oficina: ' . $error->getMessage());
        $errorMessage = 'Não foi possível salvar. Tente novamente.';
    }
}

$brand = $workshop['name'] ?? 'R Oficina';
$page = $_GET['pagina'] ?? '';
$csrf = '<input type="hidden" name="csrf" value="' . esc($_SESSION['csrf']) . '">';
$orders = [];
if ($workshop && $page !== 'identidade') {
    $stmt = $db->prepare('SELECT * FROM orders WHERE workshop_id = ? ORDER BY id DESC');
    $stmt->execute([$workshop['id']]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($brand) ?> · Gestão de oficina</title>
<style>
:root{color-scheme:dark;--bg:#111613;--surface:#1b231f;--ink:#f2f4ea;--muted:#adb9ad;--accent:#c9fb76;--line:#37483d}
*{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--ink);font:1rem/1.55 system-ui,sans-serif}header{border-bottom:1px solid var(--line);background:#151c17}header>div,main{max-width:1150px;margin:auto;padding:1.15rem clamp(1rem,4vw,3rem)}header>div,.brand,nav,.row{display:flex;align-items:center;gap:1rem;flex-wrap:wrap}header>div{justify-content:space-between}.brand{color:var(--ink);text-decoration:none;font-weight:750}.brand img,.mark{width:3rem;height:3rem;border-radius:.7rem;object-fit:contain;background:#f4f6ee}.mark{display:grid;place-items:center;background:var(--accent);color:#152217;font-size:1.5rem}.brand span:last-child{overflow-wrap:anywhere}nav a,.quiet{color:var(--muted)}nav a[aria-current]{color:var(--accent)}nav form{margin:0}
h1{font-size:clamp(2.2rem,6vw,4.35rem);letter-spacing:-.055em;line-height:1.04;margin:1.25rem 0 .55rem}h2{font-size:1.35rem;margin:0 0 1rem}p{margin:.4rem 0 1.15rem;color:var(--muted)}section{min-width:0}.grid{display:grid;gap:1.15rem;grid-template-columns:repeat(2,minmax(0,1fr));margin:1.5rem 0}.card{background:var(--surface);border:1px solid var(--line);border-radius:1rem;padding:clamp(1rem,3vw,1.75rem)}.narrow{max-width:38rem;margin:1.5rem 0}.row>label{flex:1;min-width:9rem}
label{display:block;font-weight:600;margin-bottom:.85rem}input,select,textarea{width:100%;margin-top:.3rem;border:1px solid var(--line);color:var(--ink);background:var(--bg);border-radius:.55rem;padding:.7rem;font:inherit}textarea{min-height:5.2rem;resize:vertical}input[type=checkbox]{width:auto;margin-right:.45rem}button,.button{display:inline-block;border:0;border-radius:.55rem;background:var(--accent);color:#172216;padding:.68rem 1rem;text-decoration:none;font:inherit;font-weight:750;cursor:pointer}.quiet{background:transparent;padding:.5rem}.notice{border-left:4px solid var(--accent);background:var(--surface);padding:.8rem 1rem}.error{border-color:#ff998e}.order{border-top:1px solid var(--line);padding:1rem 0;overflow-wrap:anywhere}.order:first-of-type{border:0}.order summary{cursor:pointer;font-weight:700}.order .row{margin-top:.8rem}.order select{width:auto;margin:0}.logo-preview{width:9rem;height:6rem;object-fit:contain;background:#f4f6ee;border-radius:.6rem}.hint{font-size:.9rem;color:var(--muted);font-weight:400}
@media(max-width:750px){.grid{grid-template-columns:1fr}nav{gap:.7rem}}
</style>
</head>
<body>
<header><div>
<a class="brand" href="./">
<?php if ($workshop && $workshop['logo_type']): ?><img src="?logo=1" alt="">
<?php else: ?><span class="mark" aria-hidden="true"><?= esc(brandInitial($brand)) ?></span><?php endif; ?>
<span><?= esc($brand) ?></span></a>
<?php if ($workshop): ?><nav aria-label="Navegação">
<a href="./" <?= $page !== 'identidade' ? 'aria-current="page"' : '' ?>>Ordens de serviço</a>
<a href="?pagina=identidade" <?= $page === 'identidade' ? 'aria-current="page"' : '' ?>>Identidade visual</a>
<form method="post"><?= $csrf ?><button class="quiet" name="action" value="logout">Sair</button></form>
</nav><?php endif; ?>
</div></header>
<main>
<?php if ($flash): ?><p class="notice" role="status"><?= esc($flash) ?></p><?php endif; ?>
<?php if ($errorMessage): ?><p class="notice error" role="alert"><?= esc($errorMessage) ?></p><?php endif; ?>
<?php if (!$workshop): ?>
<h1>Gestão da sua oficina</h1>
<p>Ordens de serviço e orçamentos para carros e motos.</p>
<div class="grid">
<section class="card"><h2>Entrar</h2>
<form method="post"><?= $csrf ?>
<label>E-mail<input name="email" type="email" autocomplete="username" required></label>
<label>Senha<input name="password" type="password" autocomplete="current-password" required></label>
<button name="action" value="login">Entrar</button>
</form></section>
<section class="card"><h2>Cadastrar oficina</h2>
<form method="post"><?= $csrf ?>
<label>Nome da oficina<input name="name" autocomplete="organization" maxlength="120" required></label>
<label>E-mail<input name="email" type="email" autocomplete="email" required></label>
<label>Senha <span class="hint">Mínimo de 10 caracteres</span><input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
<button name="action" value="register">Criar conta</button>
</form></section></div>
<?php elseif ($page === 'identidade'): ?>
<h1>Identidade visual</h1><p>Personalize o painel com o nome e a logo da sua oficina.</p>
<section class="card narrow"><form method="post" enctype="multipart/form-data"><?= $csrf ?>
<label>Nome da oficina<input name="name" maxlength="120" value="<?= esc($brand) ?>" required></label>
<?php if ($workshop['logo_type']): ?><img class="logo-preview" src="?logo=1" alt="Logo atual da oficina">
<label><input type="checkbox" name="remove_logo" value="1">Remover logo atual</label><?php endif; ?>
<label>Logo <span class="hint">PNG, JPG ou WebP até 2 MB</span><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
<button name="action" value="branding">Salvar identidade visual</button>
</form></section>
<?php else: ?>
<h1>Ordens de serviço</h1>
<div class="grid">
<section class="card"><h2>Nova ordem de serviço</h2>
<form method="post"><?= $csrf ?>
<div class="row"><label>Cliente<input name="customer" maxlength="160" required></label><label>WhatsApp com DDD<input name="phone" type="tel" maxlength="32" required></label></div>
<div class="row"><label>Tipo de veículo<select name="vehicle_type"><option>Carro</option><option>Moto</option></select></label><label>Placa<input name="plate" maxlength="20"></label></div>
<label>Marca e modelo<input name="vehicle" maxlength="160" required></label>
<label>Defeito ou diagnóstico<textarea name="diagnosis" maxlength="2400" required></textarea></label>
<label>Peças <span class="hint">Uma por linha no formato nome | quantidade | preço unitário</span><textarea name="parts" maxlength="5000" placeholder="Filtro de óleo | 1 | 35,00"></textarea></label>
<label>Mão de obra (R$)<input name="labor" inputmode="decimal" value="0,00" required></label>
<button name="action" value="new_order">Salvar ordem</button>
</form></section>
<section class="card"><h2>Ordens registradas</h2>
<?php if (!$orders): ?><p>As ordens que você cadastrar aparecem aqui.</p><?php endif; ?>
<?php foreach ($orders as $order):
    $lines = [$brand, 'Orçamento para ' . $order['customer'], $order['vehicle_type'] . ' · ' . $order['vehicle'] . ($order['plate'] ? ' · ' . $order['plate'] : ''), 'Diagnóstico: ' . $order['diagnosis']];
    $items = json_decode($order['parts'], true, 512, JSON_THROW_ON_ERROR);
    foreach ($items as [$part, $qty, $unit]) {
        $lines[] = "$qty × $part · " . money($unit) . ' un. · ' . money($qty * $unit);
    }
    $lines[] = 'Mão de obra: ' . money((int) $order['labor']);
    $lines[] = 'Total: ' . money((int) $order['total']);
    $whatsapp = 'https://wa.me/' . $order['phone'] . '?text=' . rawurlencode(implode("\n", $lines));
?>
<details class="order"><summary><?= esc($order['vehicle']) ?> · <?= esc($order['customer']) ?> · <?= money((int) $order['total']) ?></summary>
<p><?= nl2br(esc(implode("\n", $lines))) ?></p>
<a class="button" href="<?= esc($whatsapp) ?>" target="_blank" rel="noopener noreferrer">Abrir orçamento no WhatsApp</a>
<form class="row" method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) $order['id'] ?>">
<select name="status" aria-label="Situação da ordem"><?php foreach ($statuses as $status): ?><option <?= $status === $order['status'] ? 'selected' : '' ?>><?= esc($status) ?></option><?php endforeach; ?></select>
<button class="quiet" name="action" value="status">Atualizar situação</button></form></details>
<?php endforeach; ?></section></div>
<?php endif; ?>
</main>
</body></html>
