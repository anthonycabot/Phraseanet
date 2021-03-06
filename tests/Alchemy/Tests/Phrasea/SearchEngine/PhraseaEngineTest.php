<?php

namespace Alchemy\Tests\Phrasea\SearchEngine;

use Alchemy\Phrasea\SearchEngine\Phrasea\PhraseaEngine;
use Symfony\Component\Process\Process;

/**
 * @covers Alchemy\Phrasea\SearchEngine\Phrasea\PhraseaEngine
 */
class PhraseaEngineTest extends SearchEngineAbstractTest
{
    public function setUp()
    {
        if (!extension_loaded('phrasea2')) {
            $this->markTestSkipped('Phrasea extension is not loaded');
        }

        parent::setUp();
    }

    public function initialize()
    {
        self::$searchEngine = PhraseaEngine::create(self::$DI['app']);
        self::$searchEngineClass = 'Alchemy\Phrasea\SearchEngine\Phrasea\PhraseaEngine';
    }

    protected function updateIndex(array $stemms = [])
    {
        $appbox = self::$DI['app']['phraseanet.appbox'];
        $cmd = '/usr/local/bin/phraseanet_indexer '
            . ' -h=' . $appbox->get_host() . ' -P=' . $appbox->get_port()
            . ' -b=' . $appbox->get_dbname() . ' -u=' . $appbox->get_user()
            . ' -p=' . $appbox->get_passwd()
            . ' --default-character-set=utf8 -n -o --quit';
        if (($stemms = implode(',', $stemms)) !== '') {
            $cmd .= ' --stem='.$stemms;
        }
        $process = new Process($cmd);
        $process->run();
    }

    /**
     * @covers Alchemy\Phrasea\SearchEngine\Phrasea\PhraseaEngine
     */
    public function testAutocomplete()
    {
        return;
    }

    public function testQueryStoryId()
    {
        $this->markTestSkipped('Phrasea does not support `storyid=` request');
    }

    /**
     * @covers Alchemy\Phrasea\SearchEngine\Phrasea\PhraseaEngine::clearAllCache
     */
    public function testClearAllCache()
    {
        self::$searchEngine->initialize();

        foreach (range(1, 10) as $i) {
            phrasea_create_session(self::$DI['app']['authentication']->getUser()->getId());
        }

        $sql = 'SELECT session_id FROM cache';
        $stmt = self::$DI['app']['phraseanet.appbox']->get_connection()->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $n = count($rs);

        $sql = 'UPDATE cache SET lastaccess = :date WHERE session_id = :session_id';
        $stmt = self::$DI['app']['phraseanet.appbox']->get_connection()->prepare($sql);

        $date = new \DateTime('-2 months');

        foreach ($rs as $row) {
            $stmt->execute([
                ':date'       => $date->format(DATE_ISO8601),
                ':session_id' => $row['session_id'],
            ]);

            break;
        }
        $stmt->closeCursor();

        $date = new \DateTime('-1 months');

        self::$searchEngine->clearAllCache($date);

        $sql = 'SELECT session_id FROM cache';
        $stmt = self::$DI['app']['phraseanet.appbox']->get_connection()->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $this->assertEquals($n - 1, count($rs));

        self::$searchEngine->clearAllCache();

        $sql = 'SELECT session_id FROM cache';
        $stmt = self::$DI['app']['phraseanet.appbox']->get_connection()->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $this->assertEquals(0, count($rs));
    }

    /**
     * @covers Alchemy\Phrasea\SearchEngine\Phrasea\PhraseaEngine::clearCache
     */
    public function testClearCache()
    {
        self::$searchEngine->initialize();

        self::$searchEngine->clearAllCache();

        $first_id = phrasea_create_session(self::$DI['app']['authentication']->getUser()->getId());

        $phrasea_ses_id = phrasea_create_session(self::$DI['app']['authentication']->getUser()->getId());

        $this->assertNotEquals($first_id, $phrasea_ses_id);
        $this->assertGreaterThan(0, $phrasea_ses_id);

        self::$DI['app']['session']->set('phrasea_session_id', $phrasea_ses_id);
        self::$searchEngine->clearCache();

        $sql = 'SELECT session_id FROM cache';
        $stmt = self::$DI['app']['phraseanet.appbox']->get_connection()->prepare($sql);
        $stmt->execute();
        $rs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $this->assertEquals(1, count($rs));

        foreach ($rs as $row) {
            $this->assertEquals($first_id, $row['session_id']);
        }
    }
}
