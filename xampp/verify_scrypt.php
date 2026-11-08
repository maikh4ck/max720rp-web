<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$input=json_decode(stream_get_contents(STDIN),true);
if(!is_array($input) || !isset($input['password'],$input['salt'],$input['n'],$input['r'],$input['p'],$input['openssl'])) exit(2);
$password=(string)$input['password']; $salt=(string)$input['salt'];
$n=(int)$input['n']; $r=(int)$input['r']; $p=(int)$input['p'];
if(strlen($password)>4096 || strlen($salt)>1024 || $n<2 || ($n & ($n-1))!==0 || $n>1048576 || $r<1 || $r>32 || $p<1 || $p>16) exit(2);
try {
    $ffi=FFI::cdef('int EVP_PBE_scrypt(const char *pass,size_t passlen,const unsigned char *salt,size_t saltlen,uint64_t N,uint64_t r,uint64_t p,uint64_t maxmem,unsigned char *key,size_t keylen);',(string)$input['openssl']);
    $passBuf=$ffi->new('char['.max(1,strlen($password)).']');
    $saltBuf=$ffi->new('unsigned char['.max(1,strlen($salt)).']');
    $keyBuf=$ffi->new('unsigned char[64]');
    if($password!=='') FFI::memcpy($passBuf,$password,strlen($password));
    if($salt!=='') FFI::memcpy($saltBuf,$salt,strlen($salt));
    $ok=$ffi->EVP_PBE_scrypt($passBuf,strlen($password),$saltBuf,strlen($salt),$n,$r,$p,268435456,$keyBuf,64);
    if($ok!==1) exit(3);
    fwrite(STDOUT,bin2hex(FFI::string($keyBuf,64)));
} catch(Throwable $e) { exit(4); }

