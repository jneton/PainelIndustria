<?php

header('Content-Type: application/json; charset=utf-8');

$oracle_user = 'teste';
$oracle_pass = 'hai123';
$oracle_dsn  = '//10.140.11.147:1521/snkdbprd';

$resultado = array(
    'php' => PHP_VERSION,
    'oci8' => extension_loaded('oci8'),
    'conexao' => false
);

if (!extension_loaded('oci8')) {

    $resultado['mensagem'] = 'A extensao OCI8 nao esta instalada ou habilitada no PHP.';

    http_response_code(500);

    echo json_encode(
        $resultado,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

    exit;
}

$conn = @oci_connect(
    $oracle_user,
    $oracle_pass,
    $oracle_dsn,
    'AL32UTF8'
);

if (!$conn) {

    $erro = oci_error();

    $resultado['mensagem'] = 'Falha na conexao com o Oracle.';
    $resultado['erro'] = isset($erro['message'])
        ? $erro['message']
        : 'Erro desconhecido';

    http_response_code(500);

    echo json_encode(
        $resultado,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

    exit;
}

$resultado['conexao'] = true;
$resultado['mensagem'] = 'Conexao com o Oracle realizada com sucesso.';

$sql = 'SELECT SYSDATE AS DATA_ORACLE FROM DUAL';

$stmt = oci_parse($conn, $sql);

if (!oci_execute($stmt)) {

    $erro = oci_error($stmt);

    $resultado['mensagem'] = 'Conectou ao Oracle, mas ocorreu erro ao executar a consulta.';
    $resultado['erro'] = isset($erro['message'])
        ? $erro['message']
        : 'Erro desconhecido';

    oci_free_statement($stmt);
    oci_close($conn);

    http_response_code(500);

    echo json_encode(
        $resultado,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
    );

    exit;
}

$row = oci_fetch_assoc($stmt);

$resultado['consulta'] = true;
$resultado['data_oracle'] = isset($row['DATA_ORACLE'])
    ? $row['DATA_ORACLE']
    : null;

oci_free_statement($stmt);
oci_close($conn);

echo json_encode(
    $resultado,
    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
);