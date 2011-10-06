<?

/** 
 */
class try_test extends PHPUnit_Framework_TestCase {


    public function setUp()
    {
        $this->delay = 2;
    }

    public function tearDown()
    {
    }

    function wait($str) {
        $start = time();
        echo "$str start at $start\n";
        while(time()-$start<$this->delay) {
        }
        echo "$str stop at ".time()."\n";
    }
    
    function test_1() {
        $this->wait(__function__);
    }

    function test_2() {
        $this->wait(__function__);
    }

    
}
