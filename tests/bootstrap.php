<?php
namespace Psr\Http\Message { interface ServerRequestInterface { public function getAttribute(string $n, $d=null); public function getServerParams(): array; public function getQueryParams(): array; } interface ResponseInterface {} }
namespace Psr\Http\Server { interface RequestHandlerInterface {} interface MiddlewareInterface {} }
namespace Psr\Log { interface LoggerInterface { public function warning($m, array $c=[]); public function error($m, array $c=[]); } }
namespace Illuminate\Contracts\Cache { interface Repository {} }
namespace Illuminate\Database { interface ConnectionInterface { public function table($t); } }
namespace Flarum\Settings { interface SettingsRepositoryInterface { public function get($k,$d=null); public function set($k,$v); } }
namespace {
spl_autoload_register(function ($c) {
    $p = 'PeopleInside\\PowCaptcha\\';
    if (str_starts_with($c, $p)) { $f = __DIR__ . '/../src/' . str_replace('\\', '/', substr($c, strlen($p))) . '.php'; if (is_file($f)) require $f; }
});
class FakeSettings implements Flarum\Settings\SettingsRepositoryInterface { public array $d=[]; function get($k,$d=null){return $this->d[$k]??$d;} function set($k,$v){$this->d[$k]=$v;} }
class NullLog implements Psr\Log\LoggerInterface { public array $w=[]; function warning($m,array $c=[]){$this->w[]=$m;} function error($m,array $c=[]){$this->w[]=$m;} }
class FakeLock { function __construct(private FakeStore $s,private string $k){} function get(){ if(isset($this->s->locks[$this->k])) return false; $this->s->locks[$this->k]=1; return true;} function release(){ unset($this->s->locks[$this->k]); } }
class FakeStore { public array $locks=[]; function lock($k,$t){ return new FakeLock($this,$k);} }
class FakeCache implements Illuminate\Contracts\Cache\Repository {
    public array $d=[]; public bool $withLock=true; public bool $broken=false; private FakeStore $store;
    function __construct(){ $this->store=new FakeStore; }
    private function chk(){ if($this->broken) throw new RuntimeException('cache down'); }
    function getStore(){ return $this->store; }
    function get($k,$def=null){ $this->chk(); return array_key_exists($k,$this->d)?$this->d[$k]:$def; }
    function has($k){ $this->chk(); return array_key_exists($k,$this->d); }
    function put($k,$v,$ttl=null){ $this->chk(); $this->d[$k]=$v; return true; }
    function add($k,$v,$ttl=null){ $this->chk(); if(array_key_exists($k,$this->d)) return false; $this->d[$k]=$v; return true; }
    function increment($k,$by=1){ $this->chk(); $this->d[$k]=($this->d[$k]??0)+$by; return $this->d[$k]; }
}
class FakeReq implements Psr\Http\Message\ServerRequestInterface {
    function __construct(public string $ip, public array $q=[]) {}
    function getAttribute(string $n,$d=null){ return $n==='ipAddress'?$this->ip:$d; }
    function getServerParams(): array { return ['REMOTE_ADDR'=>$this->ip]; }
    function getQueryParams(): array { return $this->q; }
}
// In-memory query builder covering exactly what EventLogger uses.
class FakeQB { private array $w=[]; private ?int $off=null; private ?int $lim=null; private bool $desc=false;
    function __construct(private FakeDb $db){}
    function insert(array $r){ $r['id']=++$this->db->seq; $this->db->rows[]=(object)$r; return true; }
    function where($c,$a,$b=null){ if($b===null){$b=$a;$a='=';} $this->w[]=[$c,$a,$b]; return $this; }
    function orderByDesc($c){ $this->desc=true; return $this; }
    function offset($n){ $this->off=$n; return $this; } function limit($n){ $this->lim=$n; return $this; }
    private function match(){ $r=array_filter($this->db->rows,function($x){ foreach($this->w as [$c,$o,$v]){ if($o==='='&&$x->$c!==$v) return false; if($o==='<='&&!($x->$c<=$v)) return false;} return true;}); if($this->desc) usort($r,fn($a,$b)=>$b->id<=>$a->id); $r=array_values($r); if($this->off!==null)$r=array_slice($r,$this->off); if($this->lim!==null)$r=array_slice($r,0,$this->lim); return $r; }
    function value($c){ $r=$this->match(); return $r? $r[0]->$c : null; }
    function get(){ return $this->match(); }
    function delete(){ $ids=array_map(fn($x)=>$x->id,$this->match()); $n=count($ids); $this->db->rows=array_values(array_filter($this->db->rows,fn($x)=>!in_array($x->id,$ids))); return $n; }
}
class FakeDb implements Illuminate\Database\ConnectionInterface { public array $rows=[]; public int $seq=0; function table($t){ return new FakeQB($this);} }
class TVerifier extends PeopleInside\PowCaptcha\Service\PowTokenVerifier { public int $t; function __construct(...$a){ parent::__construct(...$a); $this->t=time(); } protected function now(): int { return $this->t; } }

function build(array $settings=[], ?FakeCache $cache=null): array {
    $s=new FakeSettings; $s->d=$settings; $cache??=new FakeCache; $log=new NullLog; $db=new FakeDb;
    $cg=new PeopleInside\PowCaptcha\Support\CacheGuard($cache); $ps=new PeopleInside\PowCaptcha\Service\PowSettings($s);
    $risk=new PeopleInside\PowCaptcha\Service\RiskTracker($cg,$ps,$log);
    $el=new PeopleInside\PowCaptcha\Service\EventLogger($db,$cg,$risk,$ps,$log);
    $ctx=new PeopleInside\PowCaptcha\Support\ClientContext;
    $v=new TVerifier($ps,$risk,$el,$cg,$ctx);
    return compact('s','cache','log','db','cg','ps','risk','el','ctx','v');
}
function solve(string $challenge,int $d): string { $z=str_repeat('0',$d); for($n=0;;$n++){ if(str_starts_with(hash('sha256',$challenge.':'.$n),$z)) return $challenge.':'.$n; } }
$GLOBALS['pass']=0; $GLOBALS['fail']=0;
function t(string $name, bool $ok){ if($ok){$GLOBALS['pass']++; echo "  ok   $name\n";} else {$GLOBALS['fail']++; echo "  FAIL $name\n";} }
}
