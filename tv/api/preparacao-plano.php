<?php
/*
Compatibilidade temporária.

O CT PREPARAÇÃO passou a usar:
    /tv/api/ordens-centro.php?centro=PREPARACAO

Este arquivo pode ser removido quando não houver mais versões antigas
do preparação.html em uso.
*/

$_GET['centro'] = 'PREPARACAO';
require __DIR__ . '/ordens-centro.php';
$oracle_user = 'sankhya';
$oracle_pass = 'hai123';
$oracle_dsn  = '//10.140.11.147:1521/snkdbprd';

$sqlCentros = <<<'SQL'
SELECT p1_n,
       p2_n,
       p3_n,
       p4_n,
       p5_n
  FROM (SELECT CASE
                  WHEN wcp.codwcp = 33 THEN
                   1
                  WHEN wcp.codwcp = 32 THEN
                   2
                  WHEN wcp.codwcp = 31 THEN
                   3
                  WHEN wcp.codwcp = 29 THEN
                   4
                  WHEN wcp.codwcp = 28 THEN
                   5
               END AS ordem,
               --wcp.codwcp,
               wcp.nome
          FROM tprwcp wcp
         WHERE wcp.codwcp IN (33, 32, 31, 29, 28))
pivot(MAX(nome) AS n
   FOR ordem IN(1 AS p1, 2 AS p2, 3 AS p3, 4 AS p4, 5 AS p5))
SQL;

$stmtCentros = oci_parse($conn, $sqlCentros);

if (!$stmtCentros || !oci_execute($stmtCentros)) {
    $e = oci_error($stmtCentros ?: $conn);
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao consultar os centros de trabalho.',
        'detalhe' => $e['message'] ?? 'Erro OCI'
    ], JSON_UNESCAPED_UNICODE);
    oci_close($conn);
    exit;
}

$centros = [];

while ($row = oci_fetch_assoc($stmtCentros)) {
    $centros[] = [
        'p1' => (string) $row['P1_N'],
        'p2' => (string) $row['P2_N'],
        'p3' => (string) $row['P3_N'],
        'p4' => (string) $row['P4_N'],
        'p5' => (string) $row['P5_N']
    ];
}

oci_free_statement($stmtCentros);

$sql = <<<'SQL'
WITH centros_configurados AS
 (SELECT wcp.codwcp,
         wcp.nome
    FROM tprwcp wcp
   WHERE wcp.codcwc = 44),
processos_base AS
 (SELECT DISTINCT iproc.idiproc,
                  ipa.codprodpa,
                  pro.descrprod || nvl(pro.compldesc, '') AS descproduto,
                  ipa.qtdproduzir,
                  cc.codwcp,
                  cc.nome AS nome_centro
    FROM tpriproc iproc
   INNER JOIN tprprc prc
      ON prc.idproc = iproc.idproc
   INNER JOIN tpripa ipa
      ON ipa.idiproc = iproc.idiproc
   INNER JOIN tgfpro pro
      ON pro.codprod = ipa.codprodpa
   INNER JOIN tprwxp wxp
      ON wxp.codprodpa = ipa.codprodpa
   INNER JOIN centros_configurados cc
      ON cc.codwcp = wxp.codwcp
   WHERE iproc.statusproc NOT IN ('C', 'C2', 'F', 'S', 'S2')
     AND prc.codprc >= 30
     AND iproc.dhinc >= trunc(SYSDATE, 'MM')
     AND iproc.dhinc < add_months(trunc(SYSDATE, 'MM'), 1)),
processos_sequenciados AS
 (SELECT pb.*,
         row_number() over (PARTITION BY pb.codwcp ORDER BY pb.idiproc, pb.codprodpa) AS sequencia
    FROM processos_base pb)
SELECT sequencia,
       codwcp,
       nome_centro,
       idiproc,
       codprodpa,
       descproduto,
       qtdproduzir
  FROM processos_sequenciados
 ORDER BY codwcp,
          sequencia
SQL;

$conn = @oci_connect($oracle_user, $oracle_pass, $oracle_dsn, 'AL32UTF8');

if (!$conn) {
    $e = oci_error();
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Falha na conexão com o Oracle.',
        'detalhe' => $e['message'] ?? 'Erro OCI'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$stmt = @oci_parse($conn, $sql);

if (!$stmt || !@oci_execute($stmt)) {
    $e = oci_error($stmt ?: $conn);
    http_response_code(500);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Erro ao executar a query.',
        'detalhe' => $e['message'] ?? 'Erro OCI'
    ], JSON_UNESCAPED_UNICODE);
    oci_close($conn);
    exit;
}

$dados = [];
$seq = 1;

while ($row = oci_fetch_assoc($stmt)) {
    $Sequencia = (float)($row['SEQUENCIA'] ?? 0);
    $CodWcp = (float)($row['CODWCP'] ?? 0);
    $NomeCentro = (string)($row['NOME_CENTRO'] ?? "");
    $OP = (float)($row['IDIPROC'] ?? 0);
    $Codprodpa = (float)($row['CODPRODPA'] ?? 0);
    $DescProduto = (string)($row['DESCPRODUTO'] ?? "");
    $QtdProduzir = (float)($row['QTDPRODUZIR'] ?? "");

