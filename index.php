<?php
declare(strict_types=1);

session_start(['cookie_secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off', 'cookie_httponly' => true, 'cookie_samesite' => 'Lax', 'use_strict_mode' => true]);
header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('Cache-Control: no-store');
$nonce = base64_encode(random_bytes(18));
header("Content-Security-Policy: default-src 'self'; script-src 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'; form-action 'self'");
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
        total INTEGER NOT NULL, status TEXT NOT NULL DEFAULT 'Orçamento', created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        mileage INTEGER, due_date TEXT, inspection TEXT NOT NULL DEFAULT '[]'
    )");
    $newColumns = ['mileage' => 'INTEGER', 'due_date' => 'TEXT', 'inspection' => "TEXT NOT NULL DEFAULT '[]'"];
    $columns = array_column($db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC), 'name');
    if (array_diff(array_keys($newColumns), $columns)) {
        $db->exec('BEGIN IMMEDIATE');
        try {
            $columns = array_column($db->query('PRAGMA table_info(orders)')->fetchAll(PDO::FETCH_ASSOC), 'name');
            foreach (array_diff_key($newColumns, array_fill_keys($columns, true)) as $name => $definition) {
                $db->exec("ALTER TABLE orders ADD COLUMN $name $definition");
            }
            $db->exec('COMMIT');
        } catch (Throwable $migrationError) {
            $db->exec('ROLLBACK');
            throw $migrationError;
        }
    }
    $db->exec('CREATE INDEX IF NOT EXISTS orders_by_workshop ON orders(workshop_id, id)');
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

function postedParts(int &$total): array {
    $names = $_POST['part_name'] ?? [];
    $quantities = $_POST['part_qty'] ?? [];
    $prices = $_POST['part_price'] ?? [];
    if (!is_array($names) || !is_array($quantities) || !is_array($prices) || count($names) > 25) {
        throw new DomainException('O orçamento aceita até 25 itens.');
    }
    $parts = [];
    foreach ($names as $key => $name) {
        $qty = $quantities[$key] ?? null;
        $price = $prices[$key] ?? null;
        if (!is_string($name) || !is_string($qty) || !is_string($price)) {
            throw new DomainException('Confira os itens do orçamento.');
        }
        $name = trim($name);
        if ($name === '' && trim($price) === '' && trim($qty) === '1') {
            continue;
        }
        if ($name === '' || strlen($name) > 180 || !preg_match('/^[1-9]\d{0,2}$/D', $qty)) {
            throw new DomainException('Informe o nome e a quantidade de cada item.');
        }
        $unit = cents(trim($price));
        $parts[] = [$name, (int) $qty, $unit];
        $total += (int) $qty * $unit;
    }
    return $parts;
}

function decimalPrice(int $amount): string {
    return number_format($amount / 100, 2, ',', '');
}

function shortDate(?string $date): string {
    return $date ? implode('/', array_reverse(explode('-', substr($date, 0, 10)))) : '—';
}

function orderNumber(int $id): string {
    return 'OS ' . str_pad((string) $id, 4, '0', STR_PAD_LEFT);
}

function whatsappUrl(array $order, string $brand): string {
    $lines = [
        $brand . ' · ' . orderNumber((int) $order['id']),
        'Olá, ' . $order['customer'] . '! Segue o orçamento do seu veículo.',
        $order['vehicle'] . ($order['plate'] ? ' · ' . $order['plate'] : ''),
        'Diagnóstico: ' . $order['diagnosis'],
    ];
    foreach (json_decode($order['parts'], true, 512, JSON_THROW_ON_ERROR) as [$name, $qty, $unit]) {
        $lines[] = "$qty × $name · " . money($unit) . ' un. · ' . money($qty * $unit);
    }
    $lines[] = 'Mão de obra: ' . money((int) $order['labor']);
    $lines[] = 'Total: ' . money((int) $order['total']);
    if ($order['due_date']) {
        $lines[] = 'Previsão de entrega: ' . shortDate($order['due_date']);
    }
    return 'https://wa.me/' . $order['phone'] . '?text=' . rawurlencode(implode("\n", $lines));
}

$statuses = [
    'Orçamento' => 'Aguardando aprovação',
    'Aprovado' => 'Aprovado',
    'Em serviço' => 'Em execução',
    'Pronto' => 'Pronto para entrega',
    'Entregue' => 'Entregue',
    'Recusado' => 'Recusado',
];
$inspectionItems = [
    'avarias' => 'Avarias externas registradas',
    'pneus' => 'Pneus conferidos',
    'luzes' => 'Luzes conferidas',
    'fluidos' => 'Nível de fluidos conferido',
    'pertences' => 'Pertences conferidos',
    'chaves' => 'Chaves recebidas',
];
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
        if ($action === 'save_order') {
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
            $parts = postedParts($total);
            $mileage = field('mileage');
            if ($mileage !== '' && !preg_match('/^\d{1,7}$/D', $mileage)) {
                throw new DomainException('Informe a quilometragem sem pontos ou vírgulas.');
            }
            $dueDate = field('due_date');
            if ($dueDate !== '' && (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $dueDate, $date) || !checkdate((int) $date[2], (int) $date[3], (int) $date[1]))) {
                throw new DomainException('Confira a previsão de entrega.');
            }
            $submittedInspection = $_POST['inspection'] ?? [];
            if (!is_array($submittedInspection)) {
                throw new DomainException('Confira os itens do checklist.');
            }
            $inspection = array_values(array_filter(array_keys($inspectionItems), static fn ($item) => in_array($item, $submittedInspection, true)));
            $values = [$customer, $phone, $type, textField('vehicle', 160), strtoupper(textField('plate', 20, false)), textField('diagnosis', 2400), json_encode($parts, JSON_THROW_ON_ERROR), $labor, $total, $mileage === '' ? null : (int) $mileage, $dueDate === '' ? null : $dueDate, json_encode($inspection, JSON_THROW_ON_ERROR)];
            $id = (int) field('id');
            if ($id > 0) {
                $stmt = $db->prepare('UPDATE orders SET customer = ?, phone = ?, vehicle_type = ?, vehicle = ?, plate = ?, diagnosis = ?, parts = ?, labor = ?, total = ?, mileage = ?, due_date = ?, inspection = ? WHERE workshop_id = ? AND id = ?');
                $stmt->execute([...$values, $workshop['id'], $id]);
                if (!$stmt->rowCount()) {
                    throw new DomainException('Ordem de serviço não encontrada.');
                }
                $_SESSION['flash'] = 'Ordem de serviço atualizada.';
            } else {
                $stmt = $db->prepare('INSERT INTO orders (customer, phone, vehicle_type, vehicle, plate, diagnosis, parts, labor, total, mileage, due_date, inspection, workshop_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)');
                $stmt->execute([...$values, $workshop['id']]);
                $id = (int) $db->lastInsertId();
                $_SESSION['flash'] = 'Ordem de serviço criada.';
            }
            redirect('?pagina=ordens&ver=' . $id);
        }
        if ($action === 'status' && isset($statuses[field('status')])) {
            $stmt = $db->prepare('UPDATE orders SET status = ? WHERE id = ? AND workshop_id = ?');
            $stmt->execute([field('status'), (int) field('id'), $workshop['id']]);
            redirect('?pagina=ordens&ver=' . (int) field('id'));
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
$pages = ['painel' => 'Visão geral', 'ordens' => 'Ordens de serviço', 'clientes' => 'Clientes', 'veiculos' => 'Veículos', 'identidade' => 'Identidade visual', 'nova' => 'Nova ordem'];
$page = is_string($_GET['pagina'] ?? null) && isset($pages[$_GET['pagina']]) ? $_GET['pagina'] : 'painel';
$csrf = '<input type="hidden" name="csrf" value="' . esc($_SESSION['csrf']) . '">';
$orders = $byId = $clients = $vehicles = [];
$counts = array_fill_keys(array_keys($statuses), 0);
$today = (new DateTimeImmutable('today', new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
$overdue = $activeValue = 0;
if ($workshop && $page !== 'identidade') {
    $stmt = $db->prepare('SELECT * FROM orders WHERE workshop_id = ? ORDER BY id DESC');
    $stmt->execute([$workshop['id']]);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($orders as $order) {
        $byId[$order['id']] = $order;
        $counts[$order['status']] = ($counts[$order['status']] ?? 0) + 1;
        if (!in_array($order['status'], ['Entregue', 'Recusado'], true)) {
            $activeValue += (int) $order['total'];
            $overdue += (int) ($order['due_date'] && $order['due_date'] < $today);
        }
        $clients[$order['phone']][] = $order;
        $plate = preg_replace('/[^A-Z0-9]/', '', strtoupper($order['plate']));
        $vehicleKey = $plate ?: $order['phone'] . ':' . $order['vehicle_type'] . ':' . strtolower($order['vehicle']);
        $vehicles[$vehicleKey][] = $order;
    }
}
$selected = $page === 'ordens' ? ($byId[(int) ($_GET['ver'] ?? 0)] ?? null) : null;
$edit = $page === 'nova' ? ($byId[(int) ($_GET['editar'] ?? 0)] ?? null) : null;
$reuse = $page === 'nova' ? ($byId[(int) ($_GET['reusar'] ?? 0)] ?? null) : null;
$printing = $selected && isset($_GET['imprimir']);
if ($workshop && (($page === 'ordens' && isset($_GET['ver']) && !$selected) || ($page === 'nova' && isset($_GET['editar']) && !$edit))) {
    http_response_code(404);
    $errorMessage = 'Ordem de serviço não encontrada.';
}
$query = is_string($_GET['busca'] ?? null) ? trim(substr($_GET['busca'], 0, 100)) : '';
$statusFilter = is_string($_GET['status'] ?? null) ? $_GET['status'] : '';
function matchesOrder(array $order, string $query): bool {
    if ($query === '') return true;
    $text = implode(' ', [$order['customer'], $order['phone'], $order['vehicle'], $order['plate'], orderNumber((int) $order['id'])]);
    $insensitive = function_exists('mb_stripos') ? 'mb_stripos' : 'stripos';
    if ($insensitive($text, $query) !== false) return true;
    $compact = preg_replace('/[^A-Z0-9]/', '', strtoupper($query));
    $plate = preg_replace('/[^A-Z0-9]/', '', strtoupper($order['plate']));
    return strlen($compact) >= 3 && str_contains($plate, $compact);
}
function badge(string $status, array $statuses): string {
    $color = ['Orçamento' => 'amber', 'Aprovado' => 'blue', 'Em serviço' => 'blue', 'Pronto' => 'green', 'Entregue' => 'green'][$status] ?? 'gray';
    return '<span class="badge ' . $color . '">' . esc($statuses[$status] ?? $status) . '</span>';
}
function showOrders(array $orders, array $statuses): void {
    if (!$orders) {
        echo '<p class="empty">Nenhuma ordem encontrada.</p>';
        return;
    }
    ?>
    <div class="table-wrap"><table><thead><tr><th>Ordem</th><th>Cliente e veículo</th><th>Situação</th><th>Previsão</th><th class="amount">Total</th></tr></thead><tbody>
    <?php foreach ($orders as $row): ?>
    <tr><td><a class="order-link" href="?pagina=ordens&amp;ver=<?= (int) $row['id'] ?>"><?= esc(orderNumber((int) $row['id'])) ?></a><small><?= esc(shortDate($row['created_at'])) ?></small></td>
    <td><strong><?= esc($row['customer']) ?></strong><small><?= esc($row['vehicle'] . ($row['plate'] ? ' · ' . $row['plate'] : '')) ?></small></td>
    <td><?= badge($row['status'], $statuses) ?></td><td><?= esc(shortDate($row['due_date'])) ?></td><td class="amount"><?= money((int) $row['total']) ?></td></tr>
    <?php endforeach; ?>
    </tbody></table></div>
    <?php
}
function partRow(array $part): void {
    [$name, $qty, $price] = $part;
    ?>
    <div class="part">
        <label>Descrição<input name="part_name[]" maxlength="180" value="<?= esc($name) ?>" placeholder="Ex. filtro de óleo"></label>
        <label>Qtd.<input name="part_qty[]" type="number" min="1" max="999" value="<?= esc($qty) ?>" required></label>
        <label>Unitário (R$)<input name="part_price[]" inputmode="decimal" value="<?= esc($price) ?>" placeholder="0,00"></label>
        <button class="button secondary remove" type="button" aria-label="Remover item">×</button>
    </div>
    <?php
}
function statusOptions(array $statuses, string $current): void {
    foreach ($statuses as $key => $label) {
        echo '<option value="' . esc($key) . '"' . ($key === $current ? ' selected' : '') . '>' . esc($label) . '</option>';
    }
}
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= esc($workshop ? $pages[$page] . ' · ' . $brand : 'R Oficina · Gestão de oficina') ?></title>
<style>
:root{--ink:#162333;--muted:#56667a;--nav:#14263b;--soft:#f2f5f9;--line:#dce4ed;--accent:#0b6851;--radius:12px}
*{box-sizing:border-box}body{margin:0;background:var(--soft);color:var(--ink);font:15px/1.55 "Segoe UI",system-ui,sans-serif}a{color:#075c49;text-underline-offset:.18em}h1,h2,h3,p{margin:0}h1{font-size:clamp(1.85rem,4vw,2.7rem);line-height:1.13;letter-spacing:-.04em}h2{font-size:1.2rem}h3{font-size:1rem}p,small{color:var(--muted)}button,input,select,textarea{font:inherit}:focus-visible{outline:3px solid #258d70;outline-offset:2px}
.shell{display:grid;grid-template-columns:225px minmax(0,1fr);min-height:100vh}.sidebar,.intro{background:var(--nav);color:white}.sidebar{display:flex;flex-direction:column;gap:20px;padding:23px 14px}.brand{display:flex;align-items:center;gap:10px;color:inherit;text-decoration:none;font-size:1.1rem;overflow-wrap:anywhere}.mark,.brand img{width:44px;height:44px;flex:0 0 44px;border-radius:10px;object-fit:contain;background:#fff}.mark{display:grid;place-items:center;background:#b4e9cf;color:#102c26;font-weight:800;font-size:1.25rem}.sidebar nav{display:grid;gap:4px}.sidebar nav a{color:#cfdaea;font-weight:600;text-decoration:none;padding:10px;border-radius:8px}.sidebar nav a:hover,.sidebar nav a[aria-current]{background:#29435d;color:#fff}.sidebar nav a[aria-current]{box-shadow:inset 3px 0 #a5e2c2}.signout{margin-top:auto}.signout button{border:0;color:#d5e0ef;background:transparent;padding:5px;cursor:pointer}
main{width:100%;max-width:1380px;margin:auto;padding:30px clamp(14px,4vw,38px) 44px;align-self:start;min-width:0}.toolbar,.card-head,.actions,.filters{display:flex;justify-content:space-between;align-items:center;gap:8px;flex-wrap:wrap}.toolbar{margin-bottom:19px}.toolbar p{margin-top:5px}.actions,.filters{justify-content:flex-start}.filters{align-items:end}.filters label:first-of-type{flex:1;min-width:180px}.filters label{margin:0}.card-head{margin-bottom:13px}.button{display:inline-flex;align-items:center;justify-content:center;min-height:41px;padding:8px 12px;border:1px solid transparent;border-radius:8px;background:var(--accent);color:white;font-weight:650;text-decoration:none;white-space:nowrap;cursor:pointer}.button:hover{background:#085440}.button.secondary{background:#fff;border-color:#cbd6e3;color:var(--ink)}.button.secondary:hover{background:#eaf0f7}.button:disabled{opacity:.5;cursor:default}
.card,.metric{background:#fff;border:1px solid var(--line);border-radius:var(--radius);min-width:0}.card{padding:18px;margin-bottom:14px}.metric{padding:14px}.metric strong{display:block;font-size:clamp(1.25rem,2vw,1.75rem);letter-spacing:-.04em}.metrics,.grid,.fields{display:grid;gap:12px;grid-template-columns:repeat(2,minmax(0,1fr))}.metrics{grid-template-columns:repeat(4,minmax(0,1fr));margin-bottom:14px}.fields{row-gap:0}.table-wrap{overflow:auto}table{width:100%;border-collapse:collapse;text-align:left}td,th{padding:10px 8px;vertical-align:middle;border-bottom:1px solid var(--line)}th{background:#f5f7fa;font-size:.83rem;color:var(--muted);white-space:nowrap}td small{display:block}td:first-child{min-width:78px}td:last-child,.amount{white-space:nowrap}.amount{text-align:right}.order-link{font-weight:700;white-space:nowrap}.badge{display:inline-block;padding:3px 8px;border-radius:20px;white-space:nowrap;font-size:.79rem;font-weight:650}.amber{background:#fff1ce;color:#73420d}.blue{background:#e1ecfb;color:#244f86}.green{background:#daf3e6;color:#145b45}.gray{background:#edf1f5;color:#435264}.empty{padding:20px;text-align:center;border:1px dashed var(--line);border-radius:9px}.notice{border:1px solid #b8decd;background:#ecf8f1;color:#164b36;border-radius:9px;padding:10px 12px;margin:0 0 13px}.error{border-color:#f0c4ba;background:#fff3ef;color:#8b2b1b}.warning{border-color:#ebd6a0;background:#fff8e7;color:#634710}
label{display:block;font-weight:650;font-size:.87rem;margin-bottom:11px}label small{font-weight:400}input,select,textarea{display:block;width:100%;margin-top:4px;min-height:41px;padding:7px 9px;border:1px solid #cbd6e3;border-radius:8px;background:white;color:var(--ink)}textarea{min-height:90px;resize:vertical}input[type=checkbox]{width:auto;display:inline;min-height:0}input[type=file]{padding:6px}.form-card{max-width:860px}.part{display:grid;grid-template-columns:minmax(0,1fr) 68px 113px auto;gap:7px;align-items:end}.remove{margin-bottom:11px}.total{display:flex;justify-content:space-between;gap:8px;margin-top:12px;padding:12px;background:#e8f5ee;border-radius:8px;font-weight:730}.description{margin:6px 0 15px;white-space:pre-wrap;overflow-wrap:anywhere}.facts{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px;margin-bottom:14px}.facts div{overflow-wrap:anywhere}.facts small{display:block}.history summary{cursor:pointer;padding:10px 0;border-top:1px solid var(--line)}.history summary small{margin-left:5px}.history .table-wrap{margin:6px 0 12px}.logo-preview{width:140px;height:88px;object-fit:contain;border:1px solid var(--line);border-radius:8px}.pager{display:flex;align-items:center;justify-content:center;gap:8px;margin-top:12px}
.auth{display:grid;grid-template-columns:minmax(240px,.8fr) minmax(0,1.2fr);min-height:100vh}.intro{padding:clamp(24px,5vw,60px);display:flex;flex-direction:column;justify-content:space-between;gap:30px}.intro h1{font-size:clamp(2.1rem,5vw,4.2rem);max-width:11ch}.intro p{color:#cbd9e8;margin-top:10px;max-width:35ch}.auth main{align-self:center;max-width:790px}.paper{background:#fff}.paper main{max-width:900px}.paper .brand{margin-bottom:18px}
@media(max-width:1050px){.metrics{grid-template-columns:repeat(2,minmax(0,1fr))}.auth .grid{grid-template-columns:1fr}}
@media(max-width:670px){.shell,.auth{grid-template-columns:1fr}.sidebar{padding:12px;gap:8px}.sidebar nav{display:flex;overflow:auto}.sidebar nav a{white-space:nowrap;padding:7px 8px}.signout{margin:0}.card{padding:13px}.intro h1{max-width:none}.part{grid-template-columns:minmax(0,1fr) 56px 81px 32px;gap:4px}.part label{font-size:.77rem}.remove{padding:4px}.facts{grid-template-columns:repeat(2,minmax(0,1fr))}}
@media(max-width:370px){.fields{grid-template-columns:1fr}}
@media print{body{background:white}.no-print,.sidebar{display:none!important}.shell{display:block}main{padding:0}.card{border:0;padding:0}tr{break-inside:avoid}}
</style>
</head>
<body<?= $printing ? ' class="paper"' : '' ?>>
<?php if (!$workshop): ?>
<div class="auth"><aside class="intro"><a class="brand" href="./"><span class="mark" aria-hidden="true">R</span><strong>R Oficina</strong></a>
<div><h1>Gestão da sua oficina</h1><p>Organize o atendimento de carros e motos, do orçamento à entrega.</p></div></aside>
<main>
<?php if ($errorMessage): ?><p class="notice error" role="alert"><?= esc($errorMessage) ?></p><?php endif; ?>
<div class="grid">
<section class="card"><div class="card-head"><h2>Entrar</h2></div><form method="post"><?= $csrf ?>
<label>E-mail<input name="email" type="email" autocomplete="username" required></label>
<label>Senha<input name="password" type="password" autocomplete="current-password" required></label>
<button class="button" name="action" value="login">Entrar</button></form></section>
<section class="card"><div class="card-head"><h2>Cadastrar oficina</h2></div><form method="post"><?= $csrf ?>
<label>Nome da oficina<input name="name" maxlength="120" autocomplete="organization" required></label>
<label>E-mail<input name="email" type="email" autocomplete="email" required></label>
<label>Senha <small>Mínimo de 10 caracteres</small><input name="password" type="password" minlength="10" maxlength="72" autocomplete="new-password" required></label>
<button class="button secondary" name="action" value="register">Criar conta</button></form></section>
</div></main></div>
<?php else: ?>
<?php if (!$printing): ?><div class="shell"><aside class="sidebar">
<a class="brand" href="./"><?php if ($workshop['logo_type']): ?><img src="?logo=1" alt=""><?php else: ?><span class="mark" aria-hidden="true"><?= esc(brandInitial($brand)) ?></span><?php endif; ?><strong><?= esc($brand) ?></strong></a>
<nav aria-label="Navegação da oficina"><?php foreach ($pages as $key => $title): if ($key === 'nova') continue; ?>
<a href="?pagina=<?= esc($key) ?>" <?= $page === $key ? 'aria-current="page"' : '' ?>><?= esc($title) ?></a>
<?php endforeach; ?></nav>
<form class="signout" method="post"><?= $csrf ?><button name="action" value="logout">Sair</button></form>
</aside><?php endif; ?>
<main>
<?php if ($flash): ?><p class="notice" role="status"><?= esc($flash) ?></p><?php endif; ?>
<?php if ($errorMessage): ?><p class="notice error" role="alert"><?= esc($errorMessage) ?></p><?php endif; ?>
<?php if ($page === 'painel'): ?>
<div class="toolbar"><div><h1>Visão geral</h1><p>Acompanhe os orçamentos e serviços da oficina.</p></div><a class="button" href="?pagina=nova">Nova ordem</a></div>
<div class="metrics">
<div class="metric"><small>Aguardando aprovação</small><strong><?= $counts['Orçamento'] ?></strong></div>
<div class="metric"><small>Aprovados ou em execução</small><strong><?= $counts['Aprovado'] + $counts['Em serviço'] ?></strong></div>
<div class="metric"><small>Prontos para entrega</small><strong><?= $counts['Pronto'] ?></strong></div>
<div class="metric"><small>Valor das ordens em aberto</small><strong><?= money($activeValue) ?></strong></div>
</div>
<?php if ($overdue): ?><p class="notice warning"><?= $overdue ?> <?= $overdue === 1 ? 'ordem com previsão vencida' : 'ordens com previsão vencida' ?>.</p><?php endif; ?>
<section class="card"><div class="card-head"><h2>Ordens recentes</h2><a href="?pagina=ordens">Ver todas</a></div><?php showOrders(array_slice($orders, 0, 8), $statuses); ?></section>
<?php elseif ($page === 'identidade'): ?>
<div class="toolbar"><div><h1>Identidade visual</h1><p>O nome e a logo aparecem no painel e nos documentos.</p></div></div>
<section class="card form-card"><form method="post" enctype="multipart/form-data"><?= $csrf ?>
<label>Nome da oficina<input name="name" maxlength="120" value="<?= esc($brand) ?>" required></label>
<?php if ($workshop['logo_type']): ?><img class="logo-preview" src="?logo=1" alt="Logo atual da oficina"><label><input type="checkbox" name="remove_logo" value="1"> Remover logo atual</label><?php endif; ?>
<label>Logo <small>PNG, JPG ou WebP até 2 MB</small><input type="file" name="logo" accept="image/png,image/jpeg,image/webp"></label>
<button class="button" name="action" value="branding">Salvar identidade visual</button></form></section>
<?php elseif ($page === 'nova'):
    $retry = $_SERVER['REQUEST_METHOD'] === 'POST' && field('action') === 'save_order';
    $source = $edit ?? $reuse ?? [];
    $value = static fn (string $key, mixed $default = '') => esc($retry ? field($key) : ($source[$key] ?? $default));
    $type = $retry ? field('vehicle_type') : ($source['vehicle_type'] ?? 'Carro');
    $checked = $retry ? ($_POST['inspection'] ?? []) : json_decode($edit['inspection'] ?? '[]', true, 512, JSON_THROW_ON_ERROR);
    $checked = is_array($checked) ? $checked : [];
    $partFields = [];
    if ($retry && is_array($_POST['part_name'] ?? null)) {
        foreach (array_slice($_POST['part_name'], 0, 25, true) as $key => $name) {
            $qty = $_POST['part_qty'][$key] ?? '1';
            $price = $_POST['part_price'][$key] ?? '';
            if (is_string($name) && is_string($qty) && is_string($price)) $partFields[] = [$name, $qty, $price];
        }
    } elseif ($edit) {
        foreach (json_decode($edit['parts'], true, 512, JSON_THROW_ON_ERROR) as [$name, $qty, $price]) {
            $partFields[] = [$name, $qty, decimalPrice($price)];
        }
    }
?>
<div class="toolbar"><h1><?= $edit ? 'Editar ' . esc(orderNumber((int) $edit['id'])) : 'Nova ordem de serviço' ?></h1><a class="button secondary" href="?pagina=ordens<?= $edit ? '&amp;ver=' . (int) $edit['id'] : '' ?>">Voltar</a></div>
<?php if (!isset($_GET['editar']) || $edit): ?>
<form class="form-card" method="post" id="order-form"><?= $csrf ?><input type="hidden" name="id" value="<?= (int) ($edit['id'] ?? 0) ?>">
<section class="card"><div class="card-head"><h2>Cliente e veículo</h2></div><div class="fields">
<label>Cliente<input name="customer" maxlength="160" value="<?= $value('customer') ?>" required></label>
<label>WhatsApp com DDD<input name="phone" type="tel" maxlength="32" value="<?= $value('phone') ?>" required></label>
<label>Tipo de veículo<select name="vehicle_type"><option <?= $type === 'Carro' ? 'selected' : '' ?>>Carro</option><option <?= $type === 'Moto' ? 'selected' : '' ?>>Moto</option></select></label>
<label>Marca e modelo<input name="vehicle" maxlength="160" value="<?= $value('vehicle') ?>" required></label>
<label>Placa<input name="plate" maxlength="20" value="<?= $value('plate') ?>"></label>
<label>Quilometragem (km)<input name="mileage" type="number" min="0" max="9999999" value="<?= esc($retry ? field('mileage') : ($edit['mileage'] ?? '')) ?>"></label>
</div></section>
<section class="card"><div class="card-head"><h2>Serviço e previsão</h2></div><div class="fields">
<label>Modelo de serviço<select id="service-model"><option value="">Escolher modelo</option>
<option value="Troca de óleo e filtro. Conferir nível, vazamentos e especificação do fabricante.">Troca de óleo e filtro</option>
<option value="Avaliar pastilhas, discos, fluido e funcionamento do sistema de freios.">Inspeção de freios</option>
<option value="Conferir os itens do manual para a quilometragem, incluindo fluidos, filtros, pneus e luzes.">Revisão preventiva</option>
<option value="Inspecionar corrente, coroa e pinhão. Verificar desgaste, alinhamento, folga e lubrificação.">Kit de transmissão de moto</option>
<option value="Verificar bateria, terminais e sistema de carga.">Diagnóstico de bateria</option></select></label>
<label>Previsão de entrega<input name="due_date" type="date" value="<?= esc($retry ? field('due_date') : ($edit['due_date'] ?? '')) ?>"></label>
</div><label>Defeito ou diagnóstico<textarea name="diagnosis" id="diagnosis" maxlength="2400" required><?= esc($retry ? field('diagnosis') : ($edit['diagnosis'] ?? '')) ?></textarea></label></section>
<section class="card"><div class="card-head"><h2>Checklist de entrada</h2></div><div class="fields">
<?php foreach ($inspectionItems as $key => $label): ?>
<label><input type="checkbox" name="inspection[]" value="<?= esc($key) ?>" <?= in_array($key, $checked, true) ? 'checked' : '' ?>> <?= esc($label) ?></label>
<?php endforeach; ?></div></section>
<section class="card"><div class="card-head"><h2>Peças e valores</h2><button class="button secondary" type="button" id="add-part">Adicionar item</button></div>
<div id="part-rows"><?php foreach ($partFields ?: [['', 1, '']] as $part) partRow($part); ?></div>
<template id="part-template"><?php partRow(['', 1, '']); ?></template>
<label>Mão de obra (R$)<input name="labor" inputmode="decimal" value="<?= esc($retry ? field('labor') : decimalPrice((int) ($edit['labor'] ?? 0))) ?>" required></label>
<div class="total"><strong>Total do orçamento</strong><output id="order-total" aria-live="polite"><?= money((int) ($edit['total'] ?? 0)) ?></output></div></section>
<button class="button" name="action" value="save_order">Salvar ordem</button></form>
<?php endif; ?>
<?php elseif ($page === 'ordens' && $selected):
    $id = (int) $selected['id'];
    $inspection = array_intersect_key($inspectionItems, array_fill_keys(json_decode($selected['inspection'], true, 512, JSON_THROW_ON_ERROR), true));
?>
<?php if ($printing): ?><div class="brand"><?php if ($workshop['logo_type']): ?><img src="?logo=1" alt=""><?php else: ?><span class="mark" aria-hidden="true"><?= esc(brandInitial($brand)) ?></span><?php endif; ?><strong><?= esc($brand) ?></strong></div><?php endif; ?>
<div class="toolbar"><div><h1><?= esc(orderNumber($id)) ?></h1><p><?= esc($selected['vehicle_type'] . ' · ' . $selected['vehicle']) ?> <?= badge($selected['status'], $statuses) ?></p></div>
<div class="actions no-print">
<?php if ($printing): ?><a class="button secondary" href="?pagina=ordens&amp;ver=<?= $id ?>">Voltar</a><button class="button" type="button" id="print-order">Imprimir / salvar PDF</button>
<?php else: ?><a class="button secondary" href="?pagina=ordens">Ver ordens</a><a class="button secondary" href="?pagina=nova&amp;editar=<?= $id ?>">Editar</a><a class="button secondary" href="?pagina=ordens&amp;ver=<?= $id ?>&amp;imprimir=1">Imprimir / PDF</a><a class="button" href="<?= esc(whatsappUrl($selected, $brand)) ?>" target="_blank" rel="noopener noreferrer">Abrir no WhatsApp</a><?php endif; ?>
</div></div>
<section class="card"><div class="card-head"><h2>Dados da ordem</h2></div>
<div class="facts">
<div><small>Cliente</small><strong><?= esc($selected['customer']) ?></strong></div><div><small>WhatsApp</small><?= esc($selected['phone']) ?></div>
<div><small>Placa</small><?= esc($selected['plate'] ?: '—') ?></div>
<div><small>Quilometragem</small><?= $selected['mileage'] === null ? '—' : number_format((int) $selected['mileage'], 0, ',', '.') . ' km' ?></div>
<div><small>Abertura</small><?= esc(shortDate($selected['created_at'])) ?></div><div><small>Previsão de entrega</small><?= esc(shortDate($selected['due_date'])) ?></div></div>
<h3>Defeito ou diagnóstico</h3><p class="description"><?= esc($selected['diagnosis']) ?></p>
<?php if ($inspection): ?><h3>Checklist de entrada</h3><ul><?php foreach ($inspection as $label): ?><li><?= esc($label) ?></li><?php endforeach; ?></ul><?php endif; ?>
<div class="table-wrap"><table><thead><tr><th>Descrição</th><th class="amount">Qtd.</th><th class="amount">Unitário</th><th class="amount">Subtotal</th></tr></thead><tbody>
<?php foreach (json_decode($selected['parts'], true, 512, JSON_THROW_ON_ERROR) as [$name, $qty, $price]): ?>
<tr><td><?= esc($name) ?></td><td class="amount"><?= (int) $qty ?></td><td class="amount"><?= money((int) $price) ?></td><td class="amount"><?= money($qty * $price) ?></td></tr>
<?php endforeach; ?>
<tr><td>Mão de obra</td><td class="amount">1</td><td class="amount"><?= money((int) $selected['labor']) ?></td><td class="amount"><?= money((int) $selected['labor']) ?></td></tr>
</tbody></table></div><div class="total"><strong>Total do orçamento</strong><?= money((int) $selected['total']) ?></div></section>
<?php if (!$printing): ?><section class="card"><form class="filters" method="post"><?= $csrf ?><input type="hidden" name="id" value="<?= $id ?>">
<label>Situação<select name="status"><?php statusOptions($statuses, $selected['status']); ?></select></label>
<button class="button secondary" name="action" value="status">Atualizar situação</button></form></section><?php endif; ?>
<?php elseif ($page === 'ordens' || $page === 'clientes' || $page === 'veiculos'):
    $isOrders = $page === 'ordens';
    $isClient = $page === 'clientes';
?>
<div class="toolbar"><div><h1><?= esc($pages[$page]) ?></h1><?php if (!$isOrders): ?><p>Histórico a partir das ordens registradas.</p><?php endif; ?></div><a class="button" href="?pagina=nova">Nova ordem</a></div>
<section class="card"><form class="filters" method="get"><input type="hidden" name="pagina" value="<?= esc($page) ?>">
<label>Busca<input type="search" name="busca" value="<?= esc($query) ?>" placeholder="Cliente, telefone, placa, veículo ou OS"></label>
<?php if ($isOrders): ?><label>Situação<select name="status"><option value="">Todas</option><?php statusOptions($statuses, $statusFilter); ?></select></label><?php endif; ?>
<button class="button secondary">Buscar</button></form></section>
<?php if ($isOrders):
    $filtered = array_values(array_filter($orders, static fn ($order) => (!isset($statuses[$statusFilter]) || $order['status'] === $statusFilter) && matchesOrder($order, $query)));
    $totalPages = max(1, (int) ceil(count($filtered) / 25));
    $current = max(1, min($totalPages, (int) ($_GET['p'] ?? 1)));
    $base = ['pagina' => 'ordens', 'busca' => $query, 'status' => $statusFilter];
?>
<section class="card"><div class="card-head"><h2><?= count($filtered) ?> <?= count($filtered) === 1 ? 'ordem encontrada' : 'ordens encontradas' ?></h2></div>
<?php showOrders(array_slice($filtered, ($current - 1) * 25, 25), $statuses); ?>
<?php if ($totalPages > 1): ?><div class="pager">
<?php if ($current > 1): ?><a class="button secondary" href="?<?= esc(http_build_query($base + ['p' => $current - 1])) ?>">Anterior</a><?php endif; ?>
<span><?= $current ?> de <?= $totalPages ?></span>
<?php if ($current < $totalPages): ?><a class="button secondary" href="?<?= esc(http_build_query($base + ['p' => $current + 1])) ?>">Próxima</a><?php endif; ?>
</div><?php endif; ?></section>
<?php else:
    $history = array_filter($isClient ? $clients : $vehicles, static fn ($rows) => (bool) array_filter($rows, static fn ($row) => matchesOrder($row, $query)));
?>
<section class="card history"><div class="card-head"><h2><?= count($history) ?> <?= $isClient ? 'clientes' : 'veículos' ?></h2></div>
<?php if (!$history): ?><p class="empty">Nenhum registro encontrado.</p><?php endif; ?>
<?php foreach ($history as $rows): $latest = $rows[0]; ?>
<details><summary><strong><?= esc($isClient ? $latest['customer'] : $latest['vehicle']) ?></strong><small><?= esc($isClient ? $latest['phone'] : ($latest['plate'] ?: $latest['vehicle_type'])) ?> · <?= count($rows) ?> <?= count($rows) === 1 ? 'ordem' : 'ordens' ?></small></summary>
<a class="button secondary" href="?pagina=nova&amp;reusar=<?= (int) $latest['id'] ?>">Nova ordem com esses dados</a>
<?php showOrders($rows, $statuses); ?></details>
<?php endforeach; ?></section>
<?php endif; ?>
<?php endif; ?>
</main><?php if (!$printing): ?></div><?php endif; ?><?php endif; ?>
<script nonce="<?= esc($nonce) ?>">
const printButton = document.getElementById('print-order');
if (printButton) printButton.addEventListener('click', () => window.print());
const form = document.getElementById('order-form');
if (form) {
    const items = document.getElementById('part-rows');
    const add = document.getElementById('add-part');
    const template = document.getElementById('part-template');
    const output = document.getElementById('order-total');
    const currency = new Intl.NumberFormat('pt-BR', {style: 'currency', currency: 'BRL'});
    function parseAmount(value) {
        const normalized = value.trim().replace(',', '.');
        return /^\d{1,7}(?:\.\d{1,2})?$/.test(normalized) ? Math.round(Number(normalized) * 100) : 0;
    }
    function refresh() {
        let total = parseAmount(form.elements.labor.value);
        for (const row of items.children) {
            const [name, quantity, price] = row.querySelectorAll('input');
            const qty = Number(quantity.value);
            if (name.value.trim() && Number.isInteger(qty) && qty >= 1 && qty <= 999) total += qty * parseAmount(price.value);
        }
        output.textContent = currency.format(total / 100);
        add.disabled = items.children.length >= 25;
    }
    add.addEventListener('click', () => {
        items.append(template.content.cloneNode(true));
        items.lastElementChild.querySelector('input').focus();
        refresh();
    });
    items.addEventListener('click', event => {
        const button = event.target.closest('.remove');
        if (button) {
            button.closest('.part').remove();
            refresh();
        }
    });
    form.addEventListener('input', refresh);
    const model = document.getElementById('service-model');
    model.addEventListener('change', () => {
        if (!model.value) return;
        const diagnosis = document.getElementById('diagnosis');
        const next = [diagnosis.value.trim(), model.value].filter(Boolean).join('\n');
        if (next.length <= diagnosis.maxLength) diagnosis.value = next;
        model.selectedIndex = 0;
        diagnosis.focus();
    });
    refresh();
}
</script>
</body>
</html>

