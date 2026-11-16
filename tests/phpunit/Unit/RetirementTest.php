<?php
declare(strict_types=1);
namespace DataFlair\Toplists\Tests\Unit;
use Brain\Monkey;
use Brain\Monkey\Functions;
use DataFlair\Toplists\Lifecycle\Retirement;
use PHPUnit\Framework\TestCase;
final class RetirementTest extends TestCase
{
    protected function setUp(): void { parent::setUp(); Monkey\setUp(); }
    protected function tearDown(): void { Monkey\tearDown(); unset($GLOBALS['wpdb']); parent::tearDown(); }
    private function fn(string $name): string {return 'DataFlair\\Toplists\\Lifecycle\\'.$name;}
    public function test_only_four_exact_prefixed_tables_are_dropped(): void
    {
        Functions\expect($this->fn('get_option'))->with(Retirement::OPTION)->andReturn(false);
        $db=\Mockery::mock(); $db->prefix='custom_'; $GLOBALS['wpdb']=$db;
        foreach(Retirement::TABLES as $table) {$db->shouldReceive('query')->once()->with('DROP TABLE IF EXISTS `custom_'.$table.'`')->andReturn(0);}
        Functions\expect($this->fn('wp_clear_scheduled_hook'))->twice();
        Functions\expect($this->fn('update_option'))->once()->with(Retirement::OPTION,'complete',false);
        Retirement::purge();$this->addToAssertionCount(1);
    }
    public function test_completed_migration_does_not_repeat(): void
    {
        Functions\expect($this->fn('get_option'))->with(Retirement::OPTION)->andReturn('complete');
        $db=\Mockery::mock();$db->shouldNotReceive('query');$GLOBALS['wpdb']=$db;
        Retirement::purge();$this->addToAssertionCount(1);
    }
    public function test_partial_failure_retries_all_four_without_marking_complete(): void
    {
        Functions\expect($this->fn('get_option'))->twice()->andReturn(false);
        $db=\Mockery::mock();$db->prefix='retry_';$GLOBALS['wpdb']=$db;
        foreach(Retirement::TABLES as $i=>$table) {$db->shouldReceive('query')->twice()->with('DROP TABLE IF EXISTS `retry_'.$table.'`')->andReturn($i===1 ? false : 0,0);}
        Functions\expect($this->fn('wp_clear_scheduled_hook'))->times(4);
        Functions\expect($this->fn('add_action'))->once()->with('admin_notices',\Mockery::type('callable'));
        Functions\expect($this->fn('update_option'))->once()->with(Retirement::OPTION,'complete',false);
        Retirement::purge();Retirement::purge();$this->addToAssertionCount(1);
    }
}
