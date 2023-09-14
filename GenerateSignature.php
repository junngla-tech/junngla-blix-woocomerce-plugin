<?php

/**
 * GenerateSignature.php version 1.1
 * Setear la variable de entorno REDPAY_CHECK_SIGNATURE con el valor "debug" para debuggear la firma
 */

/**
 * array_is_list for legacy php
 * https://stackoverflow.com/questions/173400/how-to-check-if-php-array-is-associative-or-sequential
 */
    function arrayislist(array $arr)
    {
        if ($arr === []) {
            return true;
        }
        return array_keys($arr) === range(0, count($arr) - 1);
    }

/**
 * @param $param Object El parametro para el cual debemos ordenar las llaves
 * @return array|mixed El parametro ordenado
 */
function sortKeys($param)
{

    if (!is_array($param)) {
        return $param;
    }

    $param = (array) $param;

    if (arrayislist($param)) {
        sort($param);
    } else {
        ksort($param);
    }

    foreach ($param as $key => $value) {
        $param[$key] = sortKeys($value);
    }

    return $param;
}

/**
 * @param $payload Object el objeto a firmar
 * @param $secret String el secreto a usar para firmar
 * @return string la firma del objeto
 */
function generateSignature($payload, $secret)
{

    $payload = sortKeys($payload);

    $message = "";

    foreach ($payload as $property => $value) {

      if ($property === "signature") { continue; }

      $message .= $property . stripslashes(json_encode($value, JSON_UNESCAPED_UNICODE));
    }

    if (getenv('REDPAY_CHECK_SIGNATURE') === 'debug') {
        echo "$message\n<br>\n<br>";
    }

    $signature = hash_hmac("sha256", $message, $secret);

    if (getenv('REDPAY_CHECK_SIGNATURE') === 'debug') {
        echo "$signature\n<br>\n<br>";
        exit;
    }

    return $signature;
}
