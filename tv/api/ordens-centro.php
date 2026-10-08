<?php
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');

$centro = $_GET['centro'] ?? '';

/*
 * Configure estes dados no servidor.
 * Nunca coloque usuário/senha do Oracle no HTML/JavaScript.
 */
$oracle_user = 'teste';
$oracle_pass = 'hai123';
$oracle_dsn  = '//10.140.11.147:1521/snkdbprd';

if ($centro !== 'FINALIZACAO01' 
    && $centro !== 'FINALIZACAO02' 
    && $centro !== 'FINALIZACAO03') {
    http_response_code(400);
    echo json_encode([
        'sucesso' => false,
        'mensagem' => 'Centro de trabalho inválido.'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$sql = <<<'SQL'
WITH processos AS
 (SELECT iproc.idiproc,
         iproc.idproc,
         iproc.dhinst,
         iproc.statusproc AS status
    FROM tpriproc iproc
   WHERE iproc.dhinst >= trunc(add_months(SYSDATE, -2), 'MM')
     AND iproc.statusproc NOT IN ('C', 'C2', 'F', 'S', 'S2')),
estoque_operacao AS
 (SELECT esr.idiproc,
         esr.idrpa,
         esr.codprodpa,
         esr.controlepa,
         SUM(esr.estoque) AS estoque
    FROM tpresr esr
   GROUP BY esr.idiproc, esr.idrpa, esr.codprodpa, esr.controlepa),
quantidade_apontada AS
 (SELECT mer.idiproc,
         mer.idiatv,
         mer.codprodpa,
         mer.controlepa,
         SUM(mer.qtd) AS qtdapontada
    FROM tprmer mer
   WHERE mer.sinal = -1
   GROUP BY mer.idiproc, mer.idiatv, mer.codprodpa, mer.controlepa),
quantidade_perdida AS
 (SELECT iat.idiproc,
         iat.idefx,
         iat.idiatv,
         apa.codprodpa,
         apa.controlepa,
         SUM(apa.qtdperda) AS qtdperdida
    FROM tprapa apa
   INNER JOIN tprapo apo ON apo.nuapo = apa.nuapo
   INNER JOIN tpriatv iat ON iat.idiatv = apo.idiatv
   WHERE apo.situacao = 'C'
   GROUP BY iat.idiproc, iat.idefx, iat.idiatv, apa.codprodpa, apa.controlepa),
dados_producao AS
 (SELECT p.idiproc,
         p.status,
         wcp.codwcp,
         wcp.nome AS nome_wcp,
         trunc(p.dhinst) AS dtprogramada,
         ipa.codprodpa,
         ipa.controlepa,
         pro.descrprod || pro.compldesc AS descproduto,
         atv.seqexecucao,
         atv.idrpaoper,
         efx.idefx,
         efx.descricao,
         ipa.qtdproduzir AS qtdop,
         iatv.idiatv,
         iatv.dhfinal
    FROM processos p
   INNER JOIN tpripa ipa ON ipa.idiproc = p.idiproc
   INNER JOIN tgfpro pro ON pro.codprod = ipa.codprodpa
   INNER JOIN tpratv atv ON atv.idproc = p.idproc
   INNER JOIN tprefx efx ON efx.idefx = atv.idefx AND efx.idproc = atv.idproc
   INNER JOIN tpriatv iatv ON iatv.idiproc = p.idiproc AND iatv.idefx = efx.idefx
   INNER JOIN tprwcp wcp ON wcp.codwcp = iatv.codwcp
   WHERE wcp.codwcp = 46),
resultado AS
 (SELECT dp.codwcp,
         dp.nome_wcp AS nome,
         dp.idiproc AS op,
         dp.status,
         dp.dtprogramada AS dtprogramacao,
         dp.codprodpa AS codprod,
         dp.descproduto AS descriproduto,
         dp.descricao,
         dp.qtdop,
         CASE
            WHEN dp.dhfinal IS NOT NULL THEN
             0
            ELSE
             nvl(eo.estoque, 0)
         END AS qtdoperacao,
         nvl(qa.qtdapontada, 0) AS qtdapontada,
         nvl(qp.qtdperdida, 0) AS qtdperdida,
         (dp.qtdop - (nvl(qa.qtdapontada, 0) + nvl(qp.qtdperdida, 0))) AS diferenca
    FROM dados_producao dp
    LEFT JOIN estoque_operacao eo
      ON eo.idiproc = dp.idiproc
     AND eo.idrpa = dp.idrpaoper
     AND eo.codprodpa = dp.codprodpa
     AND eo.controlepa = dp.controlepa
    LEFT JOIN quantidade_apontada qa
      ON qa.idiproc = dp.idiproc
     AND qa.idiatv = dp.idiatv
     AND qa.codprodpa = dp.codprodpa
     AND qa.controlepa = dp.controlepa
    LEFT JOIN quantidade_perdida qp
      ON qp.idiproc = dp.idiproc
     AND qp.idefx = dp.idefx
     AND qp.idiatv = dp.idiatv
     AND qp.codprodpa = dp.codprodpa
     AND qp.controlepa = dp.controlepa
   ORDER BY dp.idiproc)
SELECT rownum AS seq,
       r.codwcp,
       r.nome,
       r.op,
       r.status,
       to_char(r.dtprogramacao, 'MM/DD/YYYY') AS dtprogramacao,
       r.codprod,
       r.descriproduto,
       r.descricao,
       r.qtdop,
       r.qtdoperacao,
       r.qtdapontada,
       r.qtdperdida,
       r.diferenca
  FROM resultado r
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
    $qtdOp = (float)($row['QTDOP'] ?? 0);
    $qtdApontada = (float)($row['QTDAPONTADA'] ?? 0);
    $qtdPerdida = (float)($row['QTDPerdida'] ?? $row['QTDPERDIDA'] ?? 0);
    $qtdOperacao = (float)($row['QTDOPERACAO'] ?? 0);
    $diferenca = (float)($row['DIFERENCA'] ?? 0);
    $status = (string)($row['STATUS'] ?? "");

    // Regra visual para o painel:
    // finalizada quando não há diferença; em produção quando existe operação/produção apontada;
    // caso contrário, aguardando.

// A	Em andamento
// AP	Aguardando programação
// P	Em programação
// P2	Programado
// R	Criado





    if ($status = 'A') {
        $status = 'EM ANDAMENTO';
    } elseif ($status = 'AP') {
        $status = 'AGUARDANDO PROGRAMAÇÃO';
    } elseif ($status = 'P') {
        $status = 'EM PROGRAMAÇÃO';
    } elseif ($status = 'P2') {
        $status = 'PROGRAMADO';
    } else {
        $status = 'CRIADO';
    }

    $dt = $row['DTPROGRAMACAO'] ?? '';
    if ($dt instanceof DateTime) {
        $dt = $dt->format('d/m/Y');
    } elseif ($dt !== '') {
        $timestamp = strtotime((string)$dt);
        if ($timestamp !== false) {
            $dt = date('d/m/Y', $timestamp);
        } else {
            // Oracle pode retornar datas em formatos NLS diferentes.
            $dt = (string)$dt;
        }
    }

    $dados[] = [
        'sequencia'  => isset($row['SEQ']) ? (int)$row['SEQ'] : $seq,
        'ordem'      => $row['OP'] ?? '',
        'produto'    => trim(($row['CODPROD'] ?? '') . ' - ' . ($row['DESCRIPRODUTO'] ?? '')),
        'quantidade' => $qtdOp,
        'programacao'=> $dt,
        'status'     => $status,
        'codwcp'     => $row['CODWCP'] ?? '',
        'qtdoperacao'=> $qtdOperacao,
        'qtdapontada'=> $qtdApontada,
        'qtdperdida' => $qtdPerdida,
        'diferenca'  => $diferenca
    ];

    $seq++;
}

oci_free_statement($stmt);
oci_close($conn);

echo json_encode([
    'sucesso' => true,
    'centro' => $centro,
    'dados' => $dados
], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
?>
