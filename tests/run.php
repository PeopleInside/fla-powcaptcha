<?php
require __DIR__.'/bootstrap.php';
use PeopleInside\PowCaptcha\Service\PowSettings;
use PeopleInside\PowCaptcha\Support\Ip;
$IP='93.184.216.34'; $req=new FakeReq($IP);

echo "# Legacy level mapping\n";
foreach ([[null,2],[1,1],[2,2],[3,1],[4,2],[5,3],[8,3],['abc',2]] as [$in,$exp]) t("legacy ".var_export($in,true)." -> level $exp", PowSettings::levelFromLegacyDifficulty($in)===$exp);
$b=build(['peopleinside-powcaptcha.difficulty'=>'5']); t('level falls back to legacy difficulty 5 -> 3', $b['ps']->level()===3 && $b['ps']->baseDifficulty()===5);
$b=build(['peopleinside-powcaptcha.level'=>'1','peopleinside-powcaptcha.difficulty'=>'5']); t('new level key wins over legacy', $b['ps']->level()===1 && $b['ps']->baseDifficulty()===3);
$b=build(['peopleinside-powcaptcha.level'=>'3']); t('level 3 max difficulty capped at 6', $b['ps']->maxDifficulty()===6);

echo "# Happy path\n";
$b=build(); $v=$b['v']; $c=$v->issue('login',$req); t('default level 2 -> difficulty 4', $c['difficulty']===4);
t('challenge has no colon', !str_contains($c['challenge'],':'));
$tok=solve($c['challenge'],$c['difficulty']); $r=$v->verify($tok,'login',$req); t('valid solution accepted', $r->ok && $r->reason==='ok');
$r=$v->verify($tok,'login',$req); t('replay rejected', !$r->ok && $r->reason==='replayed');

echo "# Rejections\n";
$c=$v->issue('login',$req); $tok=solve($c['challenge'],4);
t('wrong action rejected', $v->verify($tok,'signup',$req)->reason==='wrong_action');
t('token from login not usable on token endpoint', $v->verify($tok,'token',$req)->reason==='wrong_action');
$parts=explode('.',$c['challenge']); $parts[3]='3'; $forged=implode('.',$parts); $tf=solve($forged,3);
t('tampered difficulty -> bad_signature', $v->verify($tf,'login',$req)->reason==='bad_signature');
$parts=explode('.',$c['challenge']); $parts[2]=(string)((int)$parts[2]+5000); $tf=solve(implode('.',$parts),4);
t('tampered issue time -> bad_signature', $v->verify($tf,'login',$req)->reason==='bad_signature');
t('missing token', $v->verify('','login',$req)->reason==='missing');
t('garbage token', $v->verify('hello','login',$req)->reason==='malformed');
t('oversized token', $v->verify(str_repeat('a',500).':1','login',$req)->reason==='malformed');
t('non numeric nonce', $v->verify($c['challenge'].':12a','login',$req)->reason==='malformed');
$c2=$v->issue('login',$req); $bad=null; for($n=0;$n<2000;$n++){ if(!str_starts_with(hash('sha256',$c2['challenge'].':'.$n),'0000')){$bad=$n;break;} }
t('insufficient work -> bad_solution', $v->verify($c2['challenge'].':'.$bad,'login',$req)->reason==='bad_solution');
$c3=$v->issue('login',$req); $tok3=solve($c3['challenge'],4); $v->t+=301;
t('expired after 5 minutes', $v->verify($tok3,'login',$req)->reason==='expired'); $v->t-=301;

echo "# Time trap (signup only)\n";
$c=$v->issue('signup',$req); $tok=solve($c['challenge'],$c['difficulty']);
t('signup submitted instantly -> too_fast', $v->verify($tok,'signup',$req)->reason==='too_fast');
$c=$v->issue('signup',$req); $tok=solve($c['challenge'],$c['difficulty']); $v->t+=3;
t('signup after 3s accepted', $v->verify($tok,'signup',$req)->ok); 
$c=$v->issue('login',$req); $tok=solve($c['challenge'],$c['difficulty']); t('login has no minimum age', $v->verify($tok,'login',$req)->ok);

echo "# Honeypot\n";
t('honeypot rejected + logged', !$v->rejectHoneypot('signup',$req)->ok && end($b['db']->rows)->reason==='honeypot');

echo "# Replay atomicity / cache failure\n";
$b=build(); $v=$b['v']; $c=$v->issue('login',$req); $tok=solve($c['challenge'],4);
$b['cache']->getStore()->locks[$b['risk']->instancePrefix().'used:'.explode('.',$c['challenge'])[1].':lock']=1;
t('lock contention -> fail closed (not accepted)', !$v->verify($tok,'login',$req)->ok);
$b=build(); $v=$b['v']; $c=$v->issue('login',$req); $tok=solve($c['challenge'],4); $b['cache']->broken=true;
$r=$v->verify($tok,'login',$req); t('cache down -> fail closed (cache_error)', !$r->ok && $r->reason==='cache_error');
$cache=new FakeCache; $b=build([],$cache);
$cache2=new class extends FakeCache { function getStore(){ return null; } }; $b2=build([],$cache2); $v2=$b2['v']; $c=$v2->issue('login',$req); $tok=solve($c['challenge'],4);
t('store without locks: add() path accepts once', $v2->verify($tok,'login',$req)->ok && $v2->verify($tok,'login',$req)->reason==='replayed');

echo "# Adaptive difficulty\n";
$b=build(); $v=$b['v']; $base=$v->issue('login',$req)['difficulty'];
for($i=0;$i<3;$i++) $v->verify('bad:1','login',$req);
$c=$v->issue('login',$req); t('3 failures -> +1', $c['difficulty']===$base+1);
for($i=0;$i<5;$i++) $v->verify('bad:1','login',$req);
$c=$v->issue('login',$req); t('8 failures -> +2 (cap 6)', $c['difficulty']===min(6,$base+2));
$other=new FakeReq('8.8.8.8'); t('another network unaffected', $v->issue('login',$other)['difficulty']===$base);
$bound=$v->issue('login',$req); $tok=solve($bound['challenge'],$bound['difficulty']);
t('bumped challenge is network-bound (other IP rejected)', $v->verify($tok,'login',$other)->reason==='wrong_network');
$bound=$v->issue('login',$req); $tok=solve($bound['challenge'],$bound['difficulty']);
t('bumped challenge accepted from same network', $v->verify($tok,'login',$req)->ok);
$b=build(['peopleinside-powcaptcha.adaptive'=>'0']); $v=$b['v']; for($i=0;$i<10;$i++) $v->verify('bad:1','login',$req);
t('adaptive OFF -> no bump', $v->issue('login',$req)['difficulty']===4);
$b=build(); $v=$b['v']; for($i=0;$i<3;$i++){ $c=$v->issue('signup',$req); $tk=solve($c['challenge'],$c['difficulty']); $v->t+=3; $v->verify($tk,'signup',$req);} 
t('3 successful signups from one network -> +1', $v->issue('signup',$req)['difficulty']===5);
t('login not affected by signup successes', $v->issue('login',$req)['difficulty']===4);
$b=build(); $v=$b['v']; for($i=0;$i<60;$i++) $v->verify('bad:1','login',new FakeReq('9.9.9.'.($i+1)));
t('site-wide attack (60 failures) -> +1 for everyone', $v->issue('login',new FakeReq('7.7.7.7'))['difficulty']===5);

echo "# Private / proxy-shared IPs\n";
$priv=new FakeReq('172.18.0.1'); $b=build(); $v=$b['v']; for($i=0;$i<20;$i++) $v->verify('bad:1','login',$priv);
t('private IP: per-IP bump disabled (no site-wide lockout)', $v->issue('login',$priv)['difficulty']===4 || $v->issue('login',$priv)['difficulty']===5);
$b=build(); t('private IP: no per-IP rate limit', $b['risk']->issuanceRetryAfter('10.0.0.5')===0 && (function() use($b){ for($i=0;$i<100;$i++) $r=$b['risk']->issuanceRetryAfter('10.0.0.5'); return $r===0; })());
t('empty IP: not tracked', !Ip::isPublic(''));

echo "# Rate limit\n";
$b=build(); $r=0; for($i=0;$i<31;$i++) $r=$b['risk']->issuanceRetryAfter($IP);
t('31st request in a minute is limited', $r>0 && $r<=60);
t('other network not limited', $b['risk']->issuanceRetryAfter('8.8.4.4')===0);

echo "# IP helpers\n";
t('IPv6 bucket is /64', Ip::bucket('2001:db8:1:2:aaaa:bbbb:cccc:dddd')===Ip::bucket('2001:db8:1:2:1111:2222:3333:4444'));
t('different /64 differ', Ip::bucket('2001:db8:1:2::1')!==Ip::bucket('2001:db8:1:3::1'));
t('IPv4-mapped IPv6 equals IPv4', Ip::bucket('::ffff:93.184.216.34')==='93.184.216.34');
t('public / private detection', Ip::isPublic('93.184.216.34') && !Ip::isPublic('192.168.1.1') && !Ip::isPublic('127.0.0.1') && !Ip::isPublic('fd00::1'));
$spoof=new class('172.18.0.1') extends FakeReq { function getServerParams(): array { return ['REMOTE_ADDR'=>'172.18.0.1','HTTP_X_FORWARDED_FOR'=>'1.2.3.4','HTTP_CLIENT_IP'=>'5.6.7.8']; } };
t('forwarded headers are ignored', Ip::fromRequest($spoof)==='172.18.0.1');
$b=build(); $b['ctx']->setIp($IP); $c=$b['v']->issue('login',null); t('falls back to ClientContext when no request', $c['difficulty']===4);

echo "# Event log\n";
$b=build(); $v=$b['v'];
for($i=0;$i<50;$i++){ $v->verify('bad:1','login',new FakeReq('9.9.'.intdiv($i,200).'.'.($i%200+1))); if($i===29) $b['cache']->d=array_filter($b['cache']->d,fn($k)=>!str_contains($k,'logw'),ARRAY_FILTER_USE_KEY);} 
$fails=array_filter($b['db']->rows,fn($r)=>$r->result==='fail'); t('log keeps at most 30 failures', count($fails)<=30 && count($fails)>=29);
for($i=0;$i<5;$i++){ $c=$v->issue('login',$req); $v->verify(solve($c['challenge'],$c['difficulty']),'login',$req);} 
$oks=array_filter($b['db']->rows,fn($r)=>$r->result==='ok'); t('successes kept separately (not pushed out by failures)', count($oks)>=1);
$cols=array_keys((array)$b['db']->rows[0]); sort($cols); t('log columns match schema with ip column', $cols===['action','created_at','difficulty','id','ip','reason','result']);
t('log stores null ip by default (log_ip disabled for GDPR)', $b['db']->rows[0]->ip === null);
$b_ip=build(['peopleinside-powcaptcha.log_ip'=>'1']); $b_ip['v']->verify('bad:1','login',$req);
t('log stores client IP when log_ip is enabled', $b_ip['db']->rows[0]->ip === $IP);
t('latest() returns IP when log_ip is enabled', $b_ip['el']->latest()[0]['ip'] === $IP);
$latest=$b['el']->latest(); t('latest() returns rows newest first', $latest[0]['id']>$latest[count($latest)-1]['id'] && isset($latest[0]['ts']));
t('latest() masks IP to null when log_ip is disabled', $latest[0]['ip'] === null);
$n=$b['el']->clear(); t('clear() empties the log', $n>0 && count($b['db']->rows)===0 && $b['el']->latest()===[]);
$b=build(); $v=$b['v']; for($i=0;$i<200;$i++) $v->verify('bad:1','login',new FakeReq('9.'.intdiv($i,250).'.'.intdiv($i,50).'.'.($i%250+1)));
t('flood: DB writes capped per minute (<=40)', count($b['db']->rows)<=40);
$b=build(['peopleinside-powcaptcha.log_enabled'=>'0']); $b['v']->verify('bad:1','login',$req); t('logging can be disabled', count($b['db']->rows)===0);
$b=build(); $b['db']=null; 

echo "\n".$GLOBALS['pass']." passed, ".$GLOBALS['fail']." failed\n"; exit($GLOBALS['fail']?1:0);
