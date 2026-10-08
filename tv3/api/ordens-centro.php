<?php
declare(strict_types=1);

/*
====================================================================
HAIALA - PAINÉIS DE PRODUÇÃO POR CENTRO DE TRABALHO
Arquivo: /tv/api/ordens-centro.php

OBJETIVO
Centralizar a alimentação de TODOS os painéis aprovados.

PADRÃO LISTA:
- FINALIZACAO01
- FINALIZACAO02
- FINALIZACAO03
- PINTURAPO
- PRETRATAMENTO

PADRÃO GRADE:
- PREPARACAO

IMPORTANTE
- Os HTMLs não devem conter credenciais Oracle.
- Recomenda-se usuário Oracle somente leitura.
- A TI deverá substituir as QUERIES PLACEHOLDER pelas consultas reais
  do Sankhya/Oracle da Haiala.
====================================================================
*/

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

/* ---------------------------------------------------------------
   RESPOSTA JSON PADRÃO
---------------------------------------------------------------- */
function responder(
    bool $sucesso,
    array $dados = [],
    ?string $erro = null,
    int $httpCode = 200,
    ?array $resumo = null,
    ?string $layout = null
): void {
    http_response_code($httpCode);

    $payload = [
        'sucesso' => $sucesso,
        'dados' => $dados,
        'erro' => $erro,
        'gerado_em' => date('c')
    ];

    if ($resumo !== null) {
        $payload['resumo'] = $resumo;
    }

    if ($layout !== null) {
        $payload['layout'] = $layout;
    }

    echo json_encode(
        $payload,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
    );

    exit;
}

/* ---------------------------------------------------------------
   CENTROS PERMITIDOS E TIPO DE LAYOUT
---------------------------------------------------------------- */
$centros = [
    'FINALIZACAO01' => [
        'descricao' => 'FINALIZAÇÃO 01 (PORTAS)',
        'layout' => 'lista'
    ],
    'FINALIZACAO02' => [
        'descricao' => 'FINALIZAÇÃO 02 (JANELAS)',
        'layout' => 'lista'
    ],
    'FINALIZACAO03' => [
        'descricao' => 'FINALIZAÇÃO 03 (PC\LAMBRI)',
        'layout' => 'lista'
    ],
    'PINTURAPO' => [
        'descricao' => 'PINTURA ELETROSTÁTICA',
        'layout' => 'lista'
    ],
    'PREPARACAO' => [
        'descricao' => 'PREPARAÇÃO',
        'layout' => 'grade'
    ],
    'PRETRATAMENTO' => [
        'descricao' => 'PRÉ-TRATAMENTO',
        'layout' => 'lista'
    ]
];

$centro = strtoupper(trim((string)($_GET['centro'] ?? '')));

if (!isset($centros[$centro])) {
    responder(false, [], 'Centro de Trabalho inválido.', 400);
}

/* ---------------------------------------------------------------
   CONEXÃO ORACLE
---------------------------------------------------------------- */
$usuario = getenv('ORACLE_USER') ?: '';
$senha   = getenv('ORACLE_PASSWORD') ?: '';
$conexao = getenv('ORACLE_CONNECTION_STRING') ?: '';

if (!extension_loaded('oci8')) {
    responder(
        false,
        [],
        'Extensão OCI8 não está habilitada no PHP/XAMPP.',
        500
    );
}

if ($usuario === '' || $senha === '' || $conexao === '') {
    responder(
        false,
        [],
        'Configuração Oracle ausente. Defina ORACLE_USER, ORACLE_PASSWORD e ORACLE_CONNECTION_STRING.',
        500
    );
}

$conn = @oci_connect($usuario, $senha, $conexao, 'AL32UTF8');

if (!$conn) {
    $e = oci_error();

    responder(
        false,
        [],
        'Falha na conexão Oracle: ' . ($e['message'] ?? 'erro desconhecido'),
        500
    );
}

/* ===============================================================
   FUNÇÃO AUXILIAR DE EXECUÇÃO
================================================================ */
function executarQuery($conn, string $sql, array $binds = []): array
{
    $stmt = @oci_parse($conn, $sql);

    if (!$stmt) {
        $e = oci_error($conn);
        throw new RuntimeException(
            'Erro ao preparar query: ' . ($e['message'] ?? 'erro desconhecido')
        );
    }

    foreach ($binds as $nome => &$valor) {
        oci_bind_by_name($stmt, $nome, $valor);
    }
    unset($valor);

    if (!@oci_execute($stmt)) {
        $e = oci_error($stmt);
        oci_free_statement($stmt);

        throw new RuntimeException(
            'Erro ao executar query: ' . ($e['message'] ?? 'erro desconhecido')
        );
    }

    $rows = [];

    while (($row = oci_fetch_assoc($stmt)) !== false) {
        $rows[] = $row;
    }

    oci_free_statement($stmt);

    return $rows;
}

/* ===============================================================
   CT PREPARAÇÃO - PADRÃO GRADE
================================================================ */
if ($centro === 'PREPARACAO') {

    /*
    ================================================================
    QUERY DO CT PREPARAÇÃO

    A query real deverá retornar UMA LINHA POR ITEM/KIT, utilizando
    obrigatoriamente os aliases abaixo:

    SEQUENCIA
        Sequência do quadro: 1 a 10

    MONTAGEM
        Valores esperados: M2, M6, M1, M5 ou M3

    CODIGO
        Código do SKU. Exemplo: 111

    SKU
        Descrição. Exemplo:
        P VEN EXTRA 085X215 DIR REQ12

    ORDEM
        Número da OP. Exemplo: 95645

    QUANTIDADE
        Quantidade prevista para o kit

    TP_KIT
        Tempo unitário do kit em minutos

    TP_TOTAL
        Tempo total em minutos

    Exemplo conceitual:
      SEQUENCIA = 1
      MONTAGEM  = M2
      CODIGO    = 111
      SKU       = P VEN EXTRA 085X215 DIR REQ12
      ORDEM     = 95645
      QUANTIDADE= 2
      TP_KIT    = 2.5
      TP_TOTAL  = 5.0

    O PHP abaixo transforma as linhas retornadas pela query no JSON
    em grade esperado pelo preparação.html.
    ================================================================
    */

    $sqlPreparacao = <<<SQL
SELECT
    1 AS SEQUENCIA,
    'M2' AS MONTAGEM,
    '111' AS CODIGO,
    'P VEN EXTRA 085X215 DIR REQ12' AS SKU,
    '95645' AS ORDEM,
    2 AS QUANTIDADE,
    2.5 AS TP_KIT,
    5.0 AS TP_TOTAL
FROM DUAL
SQL;

    try {
        $rows = executarQuery($conn, $sqlPreparacao);
    } catch (Throwable $e) {
        oci_close($conn);
        responder(false, [], $e->getMessage(), 500, null, 'grade');
    }

    /*
    ---------------------------------------------------------------
    Montagem da grade:
    [
      {
        sequencia: 1,
        m2: [...],
        m6: [...],
        m1: [...],
        m5: [...],
        m3: [...]
      }
    ]
    ---------------------------------------------------------------
    */

    $grade = [];

    for ($i = 1; $i <= 10; $i++) {
        $grade[$i] = [
            'sequencia' => $i,
            'm2' => [],
            'm6' => [],
            'm1' => [],
            'm5' => [],
            'm3' => []
        ];
    }

    foreach ($rows as $row) {
        $seq = (int)($row['SEQUENCIA'] ?? 0);
        $montagem = strtolower(trim((string)($row['MONTAGEM'] ?? '')));

        if ($seq < 1 || $seq > 10) {
            continue;
        }

        if (!in_array($montagem, ['m2', 'm6', 'm1', 'm5', 'm3'], true)) {
            continue;
        }

        $grade[$seq][$montagem][] = [
            'codigo' => (string)($row['CODIGO'] ?? ''),
            'sku' => (string)($row['SKU'] ?? ''),
            'op' => (string)($row['ORDEM'] ?? ''),
            'quantidade' => $row['QUANTIDADE'] ?? '',
            'tp_kit' => $row['TP_KIT'] ?? '',
            'tp_total' => $row['TP_TOTAL'] ?? ''
        ];
    }

    oci_close($conn);

    responder(
        true,
        array_values($grade),
        null,
        200,
        null,
        'grade'
    );
}

/* ===============================================================
   DEMAIS CTs - PADRÃO LISTA
================================================================ */

/*
====================================================================
QUERY PRINCIPAL DAS ORDENS DE PRODUÇÃO - PADRÃO LISTA

Utilizada por:
- FINALIZACAO01
- FINALIZACAO02
- FINALIZACAO03
- PINTURAPO
- PRETRATAMENTO

A query real deverá retornar os aliases:

SEQUENCIA
ORDEM
PRODUTO
QUANTIDADE
PROGRAMACAO
STATUS

Esses aliases correspondem diretamente às colunas dos HTMLs:
Seq. | Ordem | Produto / Descrição | Qtd. | Programação | Status
====================================================================
*/

$filtroCentro = $centros[$centro]['descricao'];

$sqlLista = <<<SQL
SELECT
    1 AS SEQUENCIA,
    'CONFIGURAR QUERY' AS ORDEM,
    :CENTRO AS PRODUTO,
    0 AS QUANTIDADE,
    TO_CHAR(SYSDATE, 'HH24:MI') AS PROGRAMACAO,
    'AGUARDANDO' AS STATUS
FROM DUAL
SQL;

try {
    $rowsLista = executarQuery(
        $conn,
        $sqlLista,
        [':CENTRO' => $filtroCentro]
    );
} catch (Throwable $e) {
    oci_close($conn);
    responder(false, [], $e->getMessage(), 500, null, 'lista');
}

$dados = [];

foreach ($rowsLista as $row) {
    $dados[] = [
        'sequencia'   => $row['SEQUENCIA'] ?? '',
        'ordem'       => $row['ORDEM'] ?? '',
        'produto'     => $row['PRODUTO'] ?? '',
        'quantidade'  => isset($row['QUANTIDADE'])
            ? (float)$row['QUANTIDADE']
            : 0,
        'programacao' => $row['PROGRAMACAO'] ?? '',
        'status'      => $row['STATUS'] ?? 'AGUARDANDO'
    ];
}

/*
====================================================================
RESUMO OPERACIONAL DOS 3 CARDS LARANJA

O HTML espera no objeto "resumo":

qte_prod_dia
    Quantidade produzida/confirmada no dia.

qde_perda
    Quantidade de perda registrada no dia.

tp_disponivel
    Tempo disponível do CT no dia, em minutos.

tp_performance
    Soma dos tempos de ciclo das peças efetivamente confirmadas.

O próprio HTML calcula:
Eficiência (%) = Tp Performance / Tp Disponível x 100

Também é aceito "eficiencia" como fallback, mas a preferência é
retornar tp_disponivel e tp_performance.
====================================================================
*/

/*
ATENÇÃO:
Este bloco ainda é PLACEHOLDER.
A TI deverá substituir pelos valores provenientes das consultas reais.
*/

$resumo = [
    'qte_prod_dia'   => 0,
    'qde_perda'      => 0,
    'tp_disponivel'  => 480,
    'tp_performance' => 0
];

oci_close($conn);

responder(
    true,
    $dados,
    null,
    200,
    $resumo,
    'lista'
);
