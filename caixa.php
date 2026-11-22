<?php
declare(strict_types=1);

if (!function_exists('esc')) {
    http_response_code(404);
    exit;
}

function cashKinds(): array {
    return ['receita' => 'Receita', 'despesa' => 'Despesa'];
}

function cashCategories(): array {
    return ['Serviços', 'Peças e materiais', 'Mão de obra', 'Aluguel', 'Água, luz e internet', 'Impostos e taxas', 'Equipamentos e manutenção', 'Outros'];
}

function cashPayments(): array {
    return ['Pix', 'Dinheiro', 'Cartão de débito', 'Cartão de crédito', 'Transferência', 'Boleto', 'Outro'];
}

function cashSchema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS cash_entries (
        id INTEGER PRIMARY KEY,
        workshop_id INTEGER NOT NULL REFERENCES workshops(id),
        entry_date TEXT NOT NULL,
        kind TEXT NOT NULL CHECK (kind IN ('receita', 'despesa')),
        description TEXT NOT NULL,
        category TEXT NOT NULL,
        payment TEXT NOT NULL,
        amount_cents INTEGER NOT NULL CHECK (amount_cents > 0)
    );
    CREATE INDEX IF NOT EXISTS cash_entries_period ON cash_entries(workshop_id, entry_date DESC, id DESC)");
}

function cashDate(string $value): bool {
    return preg_match('/^(\d{4})-(\d{2})-(\d{2})$/D', $value, $parts) === 1
        && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1]);
}

function cashReadQuery(string $key): string {
    return is_string($_GET[$key] ?? null) ? $_GET[$key] : '';
}

function cashPeriod(): array {
    $today = new DateTimeImmutable('today', new DateTimeZone('America/Fortaleza'));
    $start = cashReadQuery('inicio');
    $end = cashReadQuery('fim');
    if ($start === '' && $end === '') {
        return [$today->format('Y-m-01'), $today->format('Y-m-t'), ''];
    }
    if (!cashDate($start) || !cashDate($end) || $start > $end) {
        return [$today->format('Y-m-01'), $today->format('Y-m-t'), 'Confira as datas do período. Exibindo o mês atual.'];
    }
    return [$start, $end, ''];
}

function cashQuery(string $start, string $end, array $extra = []): string {
    return '?' . http_build_query(['pagina' => 'caixa', 'inicio' => $start, 'fim' => $end] + $extra);
}

function insertCashEntry(PDO $db, array $values): int {
    $stmt = $db->prepare('INSERT INTO cash_entries (entry_date, kind, description, category, payment, amount_cents, workshop_id) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute($values);
    return (int) $db->lastInsertId();
}

function saveCash(PDO $db, int $workshopId): never {
    $date = field('entry_date');
    $kind = field('kind');
    $category = field('category');
    $payment = field('payment');
    $description = textField('description', 180);
    $amount = cents(field('amount'));
    if (!cashDate($date) || !isset(cashKinds()[$kind]) || !in_array($category, cashCategories(), true) || !in_array($payment, cashPayments(), true)) {
        throw new DomainException('Confira a data, o tipo, a categoria e a forma de pagamento.');
    }
    if ($amount <= 0) {
        throw new DomainException('Informe um valor maior que zero.');
    }

    cashSchema($db);
    $values = [$date, $kind, $description, $category, $payment, $amount, $workshopId];
    $editId = cashReadQuery('editar');
    if ($editId !== '') {
        if (!ctype_digit($editId) || (int) $editId <= 0) {
            throw new DomainException('Lançamento não encontrado.');
        }
        $stmt = $db->prepare('UPDATE cash_entries SET entry_date = ?, kind = ?, description = ?, category = ?, payment = ?, amount_cents = ? WHERE workshop_id = ? AND id = ?');
        $stmt->execute([...$values, (int) $editId]);
        if (!$stmt->rowCount()) {
            throw new DomainException('Lançamento não encontrado.');
        }
        $_SESSION['flash'] = 'Lançamento atualizado.';
    } else {
        insertCashEntry($db, $values);
        $_SESSION['flash'] = 'Lançamento registrado.';
    }

    [$start, $end] = cashPeriod();
    if ($date < $start || $date > $end) {
        $month = new DateTimeImmutable($date);
        $start = $month->format('Y-m-01');
        $end = $month->format('Y-m-t');
    }
    redirect(cashQuery($start, $end));
}

function renderCash(PDO $db, int $workshopId, string $csrf): void {
    [$start, $end, $periodError] = cashPeriod();
    $editId = cashReadQuery('editar');
    $edit = null;
    try {
        cashSchema($db);
        if ($editId !== '') {
            $stmt = $db->prepare('SELECT * FROM cash_entries WHERE workshop_id = ? AND id = ?');
            $stmt->execute([$workshopId, ctype_digit($editId) ? (int) $editId : 0]);
            $edit = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
            if (!$edit) {
                http_response_code(404);
            }
        }

        $stmt = $db->prepare("SELECT
            COALESCE(SUM(CASE WHEN entry_date < :start THEN CASE WHEN kind = 'receita' THEN amount_cents ELSE -amount_cents END END), 0) AS opening,
            COALESCE(SUM(CASE WHEN entry_date >= :start AND kind = 'receita' THEN amount_cents END), 0) AS income,
            COALESCE(SUM(CASE WHEN entry_date >= :start AND kind = 'despesa' THEN amount_cents END), 0) AS expense,
            COUNT(CASE WHEN entry_date >= :start THEN 1 END) AS entry_count
            FROM cash_entries WHERE workshop_id = :workshop AND entry_date <= :end");
        $stmt->execute(['workshop' => $workshopId, 'start' => $start, 'end' => $end]);
        $summary = $stmt->fetch(PDO::FETCH_ASSOC);
        $income = (int) $summary['income'];
        $expense = (int) $summary['expense'];
        $opening = (int) $summary['opening'];
        $closing = $opening + $income - $expense;
        $pageCount = max(1, (int) ceil($summary['entry_count'] / 30));
        $current = max(1, min($pageCount, (int) cashReadQuery('p')));
        $stmt = $db->prepare('SELECT * FROM cash_entries WHERE workshop_id = ? AND entry_date BETWEEN ? AND ? ORDER BY entry_date DESC, id DESC LIMIT 30 OFFSET ?');
        $stmt->bindValue(1, $workshopId, PDO::PARAM_INT);
        $stmt->bindValue(2, $start);
        $stmt->bindValue(3, $end);
        $stmt->bindValue(4, ($current - 1) * 30, PDO::PARAM_INT);
        $stmt->execute();
        $entries = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $error) {
        error_log('R Oficina caixa: ' . $error->getMessage());
        echo '<h1>Livro de caixa</h1><p class="notice error" role="alert">O livro de caixa está indisponível. Tente novamente.</p>';
        return;
    }

    $retry = ($_SERVER['REQUEST_METHOD'] === 'POST' && field('action') === 'save_cash');
    $showForm = ($editId === '' || $edit) && ($retry || $edit || cashReadQuery('novo') === '1');
    $today = (new DateTimeImmutable('today', new DateTimeZone('America/Fortaleza')))->format('Y-m-d');
    $value = static fn (string $key, string $default = ''): string => $retry ? field($key) : ($edit[$key] ?? $default);
    ?>
    <div class="toolbar">
        <div><h1>Livro de caixa</h1><p>Registre os valores recebidos e pagos pela oficina.</p></div>
        <?php if (!$showForm): ?>
            <a class="button" href="<?= esc(cashQuery($start, $end, ['novo' => 1])) ?>#lancamento">Novo lançamento</a>
        <?php endif; ?>
    </div>
    <?php if ($editId !== '' && !$edit): ?><p class="notice error" role="alert">Lançamento não encontrado.</p><?php endif; ?>
    <?php if ($periodError): ?><p class="notice warning" role="status"><?= esc($periodError) ?></p><?php endif; ?>
    <section class="card">
        <form method="get" class="filters">
            <input type="hidden" name="pagina" value="caixa">
            <label>De<input name="inicio" type="date" value="<?= esc($start) ?>" required></label>
            <label>Até<input name="fim" type="date" value="<?= esc($end) ?>" required></label>
            <button class="button secondary">Filtrar período</button>
            <a href="?pagina=caixa">Mês atual</a>
        </form>
    </section>
    <div class="metrics">
        <div class="metric"><small>Saldo anterior</small><strong><?= money($opening) ?></strong></div>
        <div class="metric"><small>Receitas do período</small><strong><?= money($income) ?></strong></div>
        <div class="metric"><small>Despesas do período</small><strong><?= money($expense) ?></strong></div>
        <div class="metric"><small>Saldo até <?= esc(shortDate($end)) ?></small><strong><?= money($closing) ?></strong></div>
    </div>
    <?php if ($showForm): ?>
        <section class="card form-card" id="lancamento">
            <div class="card-head"><h2><?= $edit ? 'Editar lançamento' : 'Novo lançamento' ?></h2></div>
            <form method="post" action="<?= esc(cashQuery($start, $end, $edit ? ['editar' => (int) $edit['id']] : ['novo' => 1])) ?>#lancamento">
                <?= $csrf ?>
                <div class="fields">
                    <label>Tipo<select name="kind" required>
                        <?php foreach (cashKinds() as $key => $label): ?>
                            <option value="<?= esc($key) ?>" <?= $value('kind', 'receita') === $key ? 'selected' : '' ?>><?= esc($label) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Data<input name="entry_date" type="date" value="<?= esc($value('entry_date', $today)) ?>" required></label>
                    <label>Valor (R$)<input name="amount" inputmode="decimal" maxlength="10" placeholder="0,00" value="<?= esc($retry ? field('amount') : ($edit ? decimalPrice((int) $edit['amount_cents']) : '')) ?>" required></label>
                    <label>Forma de pagamento<select name="payment" required>
                        <option value="">Selecionar</option>
                        <?php foreach (cashPayments() as $payment): ?>
                            <option <?= $value('payment') === $payment ? 'selected' : '' ?>><?= esc($payment) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Categoria<select name="category" required>
                        <option value="">Selecionar</option>
                        <?php foreach (cashCategories() as $category): ?>
                            <option <?= $value('category') === $category ? 'selected' : '' ?>><?= esc($category) ?></option>
                        <?php endforeach; ?>
                    </select></label>
                    <label>Descrição<input name="description" maxlength="180" value="<?= esc($value('description')) ?>" placeholder="Ex. pagamento de revisão" required></label>
                </div>
                <div class="actions">
                    <button class="button" name="action" value="save_cash">Salvar lançamento</button>
                    <a class="button secondary" href="<?= esc(cashQuery($start, $end)) ?>">Cancelar</a>
                </div>
            </form>
        </section>
    <?php endif; ?>
    <section class="card">
        <div class="card-head"><h2>Lançamentos do período</h2></div>
        <?php if (!$entries): ?>
            <p class="empty">Nenhuma receita ou despesa registrada neste período.</p>
        <?php else: ?>
            <div class="table-wrap"><table>
                <thead><tr><th>Data</th><th>Descrição</th><th>Tipo</th><th>Pagamento</th><th class="amount">Valor</th><th>Ação</th></tr></thead>
                <tbody>
                    <?php foreach ($entries as $entry): $isIncome = $entry['kind'] === 'receita'; ?>
                        <tr>
                            <td><?= esc(shortDate($entry['entry_date'])) ?></td>
                            <td><strong><?= esc($entry['description']) ?></strong><small><?= esc($entry['category']) ?></small></td>
                            <td><span class="badge <?= $isIncome ? 'green' : 'amber' ?>"><?= $isIncome ? 'Receita' : 'Despesa' ?></span></td>
                            <td><?= esc($entry['payment']) ?></td>
                            <td class="amount"><?= esc(($isIncome ? '+' : '−') . money((int) $entry['amount_cents'])) ?></td>
                            <td><a href="<?= esc(cashQuery($start, $end, ['editar' => (int) $entry['id']])) ?>#lancamento">Editar</a></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
            <?php if ($pageCount > 1): ?>
                <div class="pager">
                    <?php if ($current > 1): ?><a class="button secondary" href="<?= esc(cashQuery($start, $end, ['p' => $current - 1])) ?>">Anterior</a><?php endif; ?>
                    <span><?= $current ?> de <?= $pageCount ?></span>
                    <?php if ($current < $pageCount): ?><a class="button secondary" href="<?= esc(cashQuery($start, $end, ['p' => $current + 1])) ?>">Próxima</a><?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php
}
