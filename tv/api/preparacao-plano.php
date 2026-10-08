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
