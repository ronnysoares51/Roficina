<?php
declare(strict_types=1);

if (!function_exists('esc')) {
    http_response_code(404);
    exit;
}

const ROFICINA_EVOLUTION = 'https://api.rstvquix.com';

function whatsappSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS whatsapp_settings (
        workshop_id INTEGER PRIMARY KEY REFERENCES workshops(id),
        instance TEXT NOT NULL UNIQUE,
        api_key TEXT NOT NULL,
        webhook_key TEXT NOT NULL UNIQUE,
        allowed_phones TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS whatsapp_incoming (
        workshop_id INTEGER NOT NULL REFERENCES workshops(id),
        event_key TEXT NOT NULL,
        received_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (workshop_id, event_key)
    );
    CREATE TABLE IF NOT EXISTS whatsapp_quotes (
        workshop_id INTEGER NOT NULL REFERENCES workshops(id),
        order_id INTEGER NOT NULL REFERENCES orders(id),
        request_key TEXT NOT NULL,
        state TEXT NOT NULL,
        sent_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (workshop_id, request_key)
    );
    CREATE INDEX IF NOT EXISTS whatsapp_quotes_order ON whatsapp_quotes(workshop_id, order_id, sent_at)");
}

function whatsappSettings(PDO $db, int $workshopId): ?array {
    $stmt = $db->prepare('SELECT * FROM whatsapp_settings WHERE workshop_id = ?');
    $stmt->execute([$workshopId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

function whatsappRequest(array $settings, string $route, ?array $payload = null): array {
    if (!function_exists('curl_init')) {
        throw new DomainException('O servidor precisa da extensão cURL para acessar a Evolution API.');
    }
    $url = ROFICINA_EVOLUTION . '/' . $route . '/' . rawurlencode($settings['instance']);
    $request = curl_init($url);
    $headers = ['Accept: application/json', 'apikey: ' . $settings['api_key']];
    $options = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 9,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
    ];
    if ($payload !== null) {
        $headers[] = 'Content-Type: application/json';
        $options[CURLOPT_POST] = true;
        $options[CURLOPT_POSTFIELDS] = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
    }
    $options[CURLOPT_HTTPHEADER] = $headers;
    curl_setopt_array($request, $options);
    $body = curl_exec($request);
    $status = (int) curl_getinfo($request, CURLINFO_RESPONSE_CODE);
    curl_close($request);
    if ($body === false || $status === 0 || $status >= 500) {
        throw new RuntimeException('A Evolution não confirmou a operação. Confira no WhatsApp antes de repetir o envio.');
    }
    if ($status === 401 || $status === 403 || $status === 404) {
        throw new DomainException('A Evolution recusou a conexão. Confira a instância e a chave.');
    }
    if ($status < 200 || $status >= 300) {
        throw new DomainException('A Evolution recusou a operação. Confira se a instância está conectada e se o número é válido.');
    }
    $result = json_decode($body, true);
    if (!is_array($result)) {
        throw new RuntimeException('A Evolution retornou uma resposta inesperada.');
    }
    return $result;
}

function whatsappSend(array $settings, string $number, string $message): void {
    $result = whatsappRequest($settings, 'message/sendText', ['number' => $number, 'text' => $message, 'linkPreview' => false]);
    if (!is_string($result['key']['id'] ?? null) || $result['key']['id'] === '' || ($result['status'] ?? null) === 'ERROR') {
        throw new RuntimeException('A Evolution não confirmou o envio.');
    }
}

function handleWhatsAppAction(PDO $db, array $workshop, string $action): never {
    $workshopId = (int) $workshop['id'];
    whatsappSchema($db);
    $settings = whatsappSettings($db, $workshopId);

    if ($action === 'save_whatsapp') {
        $instance = field('wa_instance');
        $key = field('wa_key');
        if (!preg_match('/^roficina-[a-z0-9][a-z0-9-]{1,47}$/D', $instance)) {
            throw new DomainException('Use uma instância exclusiva que comece com roficina-, por exemplo roficina-minha-oficina.');
        }
        if ($key === '' && ($settings['instance'] ?? '') === $instance) {
            $key = $settings['api_key'];
        }
        if (!preg_match('/^[\x21-\x7E]{8,256}$/D', $key)) {
            throw new DomainException('Informe a chave da própria instância Evolution.');
        }
        $rawNumbers = preg_split('/[,;\r\n]+/', textField('wa_phones', 300));
        $numbers = [];
        foreach ($rawNumbers as $number) {
            if (trim($number) !== '') {
                $numbers[] = orderWhatsAppNumber($number);
            }
        }
        $numbers = array_values(array_unique($numbers));
        if (!$numbers || count($numbers) > 10) {
            throw new DomainException('Informe de um a dez números operadores, separados por vírgula.');
        }
        try {
            whatsappRequest(['instance' => $instance, 'api_key' => $key], 'instance/connectionState');
        } catch (RuntimeException $error) {
            throw new DomainException('A Evolution não respondeu. Confira a instância e tente novamente.');
        }
        $webhookKey = ($settings['instance'] ?? '') === $instance ? $settings['webhook_key'] : bin2hex(random_bytes(32));
        $stmt = $db->prepare('INSERT INTO whatsapp_settings (workshop_id, instance, api_key, webhook_key, allowed_phones) VALUES (?, ?, ?, ?, ?)
            ON CONFLICT(workshop_id) DO UPDATE SET instance = excluded.instance, api_key = excluded.api_key, webhook_key = excluded.webhook_key, allowed_phones = excluded.allowed_phones');
        try {
            $stmt->execute([$workshopId, $instance, $key, $webhookKey, json_encode($numbers, JSON_THROW_ON_ERROR)]);
        } catch (PDOException $error) {
            if ($error->getCode() === '23000') {
                throw new DomainException('Essa instância já está vinculada. Use uma instância exclusiva desta oficina.');
            }
            throw $error;
        }
        $_SESSION['flash'] = 'Configuração salva. A URL de recebimento está disponível abaixo.';
    } elseif ($action === 'test_whatsapp') {
        if (!$settings) throw new DomainException('Salve uma instância primeiro.');
        try {
            $result = whatsappRequest($settings, 'instance/connectionState');
        } catch (RuntimeException $error) {
            throw new DomainException('Não foi possível consultar a Evolution agora.');
        }
        $state = $result['instance']['state'] ?? $result['state'] ?? '';
        $_SESSION['flash'] = $state === 'open' ? 'WhatsApp conectado na Evolution.' : 'A Evolution respondeu. Conecte o WhatsApp no gerenciador da instância.';
    } elseif ($action === 'reset_whatsapp') {
        if (!$settings) throw new DomainException('Salve uma instância primeiro.');
        $stmt = $db->prepare('UPDATE whatsapp_settings SET webhook_key = ? WHERE workshop_id = ?');
        $stmt->execute([bin2hex(random_bytes(32)), $workshopId]);
        $_SESSION['flash'] = 'URL renovada. Atualize o webhook na Evolution.';
    } elseif ($action === 'send_quote') {
        $rawOrderId = field('order_id');
        $nonce = field('send_nonce');
        if (!ctype_digit($rawOrderId) || !preg_match('/^[a-f0-9]{36}$/D', $nonce)) {
            throw new DomainException('Abra a ordem novamente e tente enviar.');
        }
        $orderId = (int) $rawOrderId;
        $returnTo = '?pagina=ordens&ver=' . $orderId;
        if (!$settings) {
            throw new DomainException('Configure a conexão na aba WhatsApp antes de enviar pelo painel.');
        }
        $stmt = $db->prepare('SELECT * FROM orders WHERE id = ? AND workshop_id = ?');
        $stmt->execute([$orderId, $workshopId]);
        $order = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$order) throw new DomainException('Ordem de serviço não encontrada.');
        $claim = $db->prepare("INSERT INTO whatsapp_quotes (workshop_id, order_id, request_key, state) VALUES (?, ?, ?, 'pending')
            ON CONFLICT(workshop_id, request_key) DO NOTHING");
        $claim->execute([$workshopId, $orderId, $nonce]);
        if (!$claim->rowCount()) {
            $_SESSION['flash'] = 'Esse pedido de envio já foi processado ou está em andamento.';
            redirect($returnTo);
        }

        $update = $db->prepare('UPDATE whatsapp_quotes SET state = ? WHERE workshop_id = ? AND request_key = ?');
        try {
            whatsappSend($settings, orderWhatsAppNumber((string) $order['phone']), quoteWhatsAppText($order, $workshop['name']));
            $update->execute(['accepted', $workshopId, $nonce]);
        } catch (DomainException $error) {
            $update->execute(['rejected', $workshopId, $nonce]);
            throw $error;
        } catch (Throwable $error) {
            $update->execute(['unknown', $workshopId, $nonce]);
            throw new DomainException('O provedor não confirmou o envio. Confira a conversa antes de tentar novamente.');
        }
        $_SESSION['flash'] = 'Orçamento aceito pelo provedor do WhatsApp. A entrega ao cliente ainda depende do WhatsApp.';
        redirect($returnTo);
    }
    redirect('?pagina=whatsapp');
}

function whatsappCommandHelp(): string {
    return "Comandos do R Oficina:\n"
        . "RECEITA 150,00 | Troca de óleo | Serviços | Pix\n"
        . "DESPESA 45,90 | Compra de filtro | Peças e materiais | Dinheiro\n"
        . "OS | João | 85999999999 | carro | Fiat Uno | Revisar freios | 80,00 | ABC1D23\n"
        . "No caixa, categoria e pagamento são opcionais. Na OS, mão de obra e placa são opcionais.";
}

function whatsappCommand(string $message): ?array {
    $message = trim($message);
    if (strlen($message) > 3000 || !preg_match('~^/?(receita|despesa|os|ajuda)(?=$|[\s|])(.*)$~isuD', $message, $matches)) {
        return null;
    }
    $parts = array_map('trim', explode('|', ltrim(trim($matches[2]), '|')));
    return [strtolower($matches[1]), $parts];
}

function whatsappChoice(string $input, array $options, string $default): string {
    if ($input === '') return $default;
    foreach ($options as $option) {
        $same = function_exists('mb_strtolower')
            ? mb_strtolower($input, 'UTF-8') === mb_strtolower($option, 'UTF-8')
            : strcasecmp($input, $option) === 0;
        if ($same) return $option;
    }
    throw new DomainException('Categoria ou forma de pagamento inválida. Digite AJUDA para ver exemplos.');
}

function whatsappCommandAmount(string $input): int {
    return cents(trim((string) preg_replace('/^R\$\s*/i', '', $input)));
}

function whatsappApplyCommand(PDO $db, int $workshopId, array $command): string {
    [$type, $parts] = $command;
    if ($type === 'ajuda') return whatsappCommandHelp();
    if ($type === 'receita' || $type === 'despesa') {
        if (count($parts) < 2 || count($parts) > 4 || $parts[1] === '' || strlen($parts[1]) > 180) {
            throw new DomainException('Use RECEITA 150,00 | Descrição ou DESPESA 45,90 | Descrição.');
        }
        [$amount, $description, $category, $payment] = array_pad($parts, 4, '');
        $value = whatsappCommandAmount($amount);
        if ($value <= 0) throw new DomainException('Informe um valor maior que zero.');
        $category = whatsappChoice($category, cashCategories(), 'Outros');
        $payment = whatsappChoice($payment, cashPayments(), 'Outro');
        $date = (new DateTimeImmutable('today', new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
        $id = insertCashEntry($db, [$date, $type, $description, $category, $payment, $value, $workshopId]);
        return ucfirst($type) . ' registrada no caixa #' . $id . ' de ' . money($value) . '.';
    }
    if (count($parts) < 5 || count($parts) > 7) {
        throw new DomainException('Use OS | Cliente | WhatsApp | carro ou moto | Veículo | Diagnóstico | Mão de obra | Placa.');
    }
    [$customer, $phone, $vehicleType, $vehicle, $diagnosis, $labor, $plate] = array_pad($parts, 7, '');
    $vehicleType = ucfirst(strtolower($vehicleType));
    if ($customer === '' || strlen($customer) > 160 || $vehicle === '' || strlen($vehicle) > 160 || $diagnosis === '' || strlen($diagnosis) > 2400 || strlen($plate) > 20 || !in_array($vehicleType, ['Carro', 'Moto'], true)) {
        throw new DomainException('Confira cliente, tipo de veículo, modelo, diagnóstico e placa. Digite AJUDA para ver um exemplo.');
    }
    $labor = $labor === '' ? 0 : whatsappCommandAmount($labor);
    $id = insertWorkshopOrder($db, [$customer, orderWhatsAppNumber($phone), $vehicleType, $vehicle, strtoupper($plate), $diagnosis, '[]', $labor, $labor, null, null, '[]'], $workshopId);
    return orderNumber($id) . ' aberta para ' . $vehicle . '.';
}

function whatsappSender(array $key): ?string {
    $jid = $key['remoteJid'] ?? '';
    if (!is_string($jid)) return null;
    if (preg_match('/@lid$/D', $jid)) {
        $jid = $key['remoteJidAlt'] ?? '';
    }
    if (!is_string($jid) || !preg_match('/^(55\d{10,11})(?::\d+)?@(?:s\.whatsapp\.net|c\.us)$/D', $jid, $matches)) {
        return null;
    }
    return $matches[1];
}

function whatsappRespond(int $status): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    echo $status < 300 ? '{"ok":true}' : '{"ok":false}';
    exit;
}

function receiveWhatsApp(PDO $db): never {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') whatsappRespond(405);
    $token = $_GET['wa_webhook'] ?? '';
    if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) whatsappRespond(404);
    try {
        whatsappSchema($db);
        $stmt = $db->prepare('SELECT * FROM whatsapp_settings WHERE webhook_key = ?');
        $stmt->execute([$token]);
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$settings) whatsappRespond(404);
        $raw = file_get_contents('php://input', false, null, 65537);
        if ($raw === false || strlen($raw) > 65536) whatsappRespond(413);
        $payload = json_decode($raw, true);
        if (!is_array($payload)) whatsappRespond(400);
        if (!in_array($payload['event'] ?? '', ['messages.upsert', 'MESSAGES_UPSERT'], true)
            || ($payload['instance'] ?? null) !== $settings['instance']) whatsappRespond(200);

        $data = $payload['data'] ?? [];
        $key = $data['key'] ?? [];
        if (!is_array($data) || !is_array($key) || ($key['fromMe'] ?? null) !== false) whatsappRespond(200);
        $timestamp = $data['messageTimestamp'] ?? null;
        if (is_numeric($timestamp) && (int) $timestamp < time() - 3600) whatsappRespond(200);
        $sender = whatsappSender($key);
        $allowed = json_decode($settings['allowed_phones'], true, 512, JSON_THROW_ON_ERROR);
        if (!$sender || !in_array($sender, $allowed, true)) whatsappRespond(200);
        $message = $data['message']['conversation'] ?? $data['message']['extendedTextMessage']['text'] ?? null;
        $messageId = $key['id'] ?? null;
        if (!is_string($message) || !is_string($messageId) || $messageId === '' || strlen($messageId) > 150) whatsappRespond(200);
        $command = whatsappCommand($message);
        if ($command === null) whatsappRespond(200);

        require_once __DIR__ . '/caixa.php';
        if (in_array($command[0], ['receita', 'despesa'], true)) cashSchema($db);
        $workshopId = (int) $settings['workshop_id'];
        $eventKey = hash('sha256', $settings['instance'] . '|' . $sender . '|' . $messageId);
        $db->beginTransaction();
        try {
            $stmt = $db->prepare('INSERT INTO whatsapp_incoming (workshop_id, event_key) VALUES (?, ?) ON CONFLICT DO NOTHING');
            $stmt->execute([$workshopId, $eventKey]);
            if (!$stmt->rowCount()) {
                $db->rollBack();
                whatsappRespond(200);
            }
            try {
                $reply = whatsappApplyCommand($db, $workshopId, $command);
            } catch (DomainException $error) {
                $reply = $error->getMessage();
            }
            $db->commit();
        } catch (Throwable $error) {
            if ($db->inTransaction()) $db->rollBack();
            throw $error;
        }
        try {
            whatsappSend($settings, $sender, $reply);
        } catch (Throwable $error) {
            error_log('R Oficina WhatsApp: resposta ao comando não confirmada.');
        }
        whatsappRespond(200);
    } catch (Throwable $error) {
        error_log('R Oficina WhatsApp: processamento do webhook falhou.');
        whatsappRespond(503);
    }
}

function renderWhatsApp(PDO $db, array $workshop, string $csrf): void {
    whatsappSchema($db);
    $settings = whatsappSettings($db, (int) $workshop['id']);
    $numbers = $settings ? implode(', ', json_decode($settings['allowed_phones'], true, 512, JSON_THROW_ON_ERROR)) : '';
    ?>
    <div class="toolbar"><div><h1>WhatsApp</h1><p>Envie orçamentos e receba comandos da oficina.</p></div></div>
    <section class="card form-card">
        <div class="card-head"><h2>Conexão Evolution API</h2></div>
        <form method="post" action="?pagina=whatsapp">
            <?= $csrf ?>
            <div class="fields">
                <label>Instância<input name="wa_instance" value="<?= esc($settings['instance'] ?? '') ?>" maxlength="56" placeholder="roficina-minha-oficina" required></label>
                <label>Chave da instância<input name="wa_key" type="password" autocomplete="new-password" maxlength="256" <?= $settings ? '' : 'required' ?>></label>
            </div>
            <label>Números autorizados para comandos<input name="wa_phones" value="<?= esc($numbers) ?>" maxlength="300" placeholder="85999999999, 85988888888" required></label>
            <p>Use uma instância exclusiva do R Oficina e envie os comandos a partir de outro número autorizado.</p>
            <button class="button" name="action" value="save_whatsapp">Salvar conexão</button>
        </form>
    </section>
    <?php if ($settings): ?>
    <section class="card form-card">
        <h2>Recebimento de comandos</h2>
        <p>No gerenciador Evolution, configure o webhook da instância, habilite MESSAGES_UPSERT e deixe as opções de URL por evento e Base64 desativadas.</p>
        <details><summary>Ver URL secreta do webhook</summary>
            <label>URL para a Evolution<input readonly value="<?= esc('https://roficina.rstvquix.com/?wa_webhook=' . $settings['webhook_key']) ?>"></label>
        </details>
        <form class="actions" method="post" action="?pagina=whatsapp">
            <?= $csrf ?><button class="button secondary" name="action" value="test_whatsapp">Testar conexão</button>
            <button class="button secondary" name="action" value="reset_whatsapp">Gerar nova URL do webhook</button>
        </form>
    </section>
    <?php endif; ?>
    <section class="card form-card">
        <h2>Exemplos de comandos</h2>
        <p class="description"><?= esc(whatsappCommandHelp()) ?></p>
        <p>Para enviar orçamento ao cliente, abra a ordem de serviço e use <strong>Enviar orçamento pelo WhatsApp</strong>.</p>
    </section>
    <?php
}
