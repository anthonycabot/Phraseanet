<?php

/*
 * This file is part of Phraseanet
 *
 * (c) 2005-2014 Alchemy
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Alchemy\Phrasea\SearchEngine\Phrasea;

use Alchemy\Phrasea\Application;
use Alchemy\Phrasea\Model\Entities\FeedEntry;
use Alchemy\Phrasea\SearchEngine\SearchEngineInterface;
use Alchemy\Phrasea\SearchEngine\SearchEngineOptions;
use Alchemy\Phrasea\SearchEngine\SearchEngineResult;
use Alchemy\Phrasea\SearchEngine\SearchEngineSuggestion;
use Alchemy\Phrasea\Exception\RuntimeException;
use Doctrine\Common\Collections\ArrayCollection;
use Symfony\Component\Translation\TranslatorInterface;

class PhraseaEngine implements SearchEngineInterface
{
    private $initialized;

    private $app;
    private $dateFields;
    private $configuration;
    private $queries = [];
    private $arrayq = [];
    private $colls = [];
    private $qp = [];
    private $needthesaurus = [];
    private $configurationPanel;
    private $resetCacheNextQuery = false;
    private $sortFields = null;

    /**
     * {@inheritdoc}
     */
    public function __construct(Application $app)
    {
        if (!extension_loaded('phrasea2')) {
            throw new RuntimeException('Phrasea2 is required to use Phrasea search engine.');
        }

        $this->app = $app;
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return 'Phrasea';
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableDateFields()
    {
        if (!$this->dateFields) {
            $this->dateFields = [];
            foreach ($this->app['phraseanet.appbox']->get_databoxes() as $databox) {
                foreach ($databox->get_meta_structure() as $databox_field) {
                    if ($databox_field->get_type() != \databox_field::TYPE_DATE) {
                        continue;
                    }

                    $this->dateFields[] = $databox_field->get_name();
                }
            }

            $this->dateFields = array_unique($this->dateFields);
        }

        return $this->dateFields;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfiguration()
    {
        if (!$this->configuration) {
            $this->configuration = $this->getConfigurationPanel()->getConfiguration();
        }

        return $this->configuration;
    }

    /**
     * {@inheritdoc}
     */
    public function getDefaultSort()
    {
        $configuration = $this->getConfiguration();

        return $configuration['default_sort'];
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableSort()
    {
        if ($this->sortFields == null) {
            $this->sortFields = array();
            foreach ($this->app['phraseanet.appbox']->get_databoxes() as $databox) {
                foreach ($databox->get_meta_structure() as $databox_field) {
                    if ($databox_field->get_type() == \databox_field::TYPE_DATE
                            || $databox_field->get_type() == \databox_field::TYPE_NUMBER) {
                        $this->sortFields[] = $databox_field->get_name();
                    }
                }
            }
            $this->sortFields = array_unique($this->sortFields);
        }

        $sort = ['' => $this->app->trans('No sort')];

        foreach ($this->sortFields as $field) {
            $sort[$field] = $field;
        }

        return $sort;
    }

    /**
     * {@inheritdoc}
     */
    public function isStemmingEnabled()
    {
        $configuration = $this->getConfiguration();

        return (Boolean) $configuration['stemming_enabled'];
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableOrder()
    {
        return [
            'desc' => $this->app->trans('descendant'),
            'asc'  => $this->app->trans('ascendant'),
        ];
    }

    /**
     * {@inheritdoc}
     */
    public function hasStemming()
    {
        return true;
    }

    /**
     * Initializes Phrasea Engine.
     *
     * It opens the connection and creates the session
     *
     * @return PhraseaEngine
     * @throws RuntimeException
     */
    public function initialize()
    {
        if ($this->initialized) {
            return $this;
        }

        $connexion = $this->app['conf']->get(['main', 'database']);

        $hostname = $connexion['host'];
        $port = (int) $connexion['port'];
        $user = $connexion['user'];
        $password = $connexion['password'];
        $dbname = $connexion['dbname'];

        if (!extension_loaded('phrasea2')) {
            throw new RuntimeException('Phrasea extension is required');
        }

        if (!function_exists('phrasea_conn')) {
            throw new RuntimeException('Phrasea extension requires upgrade');
        }

        if (phrasea_conn($hostname, $port, $user, $password, $dbname) !== true) {
            throw new RuntimeException('Unable to initialize Phrasea connection');
        }

        $this->initialized = true;

        return $this;
    }

    /**
     * Checks if the Phraseanet session is still valid. Creates a new one if required.
     *
     * @return PhraseaEngine
     * @throws \RuntimeException
     * @throws \Exception_InternalServerError
     */
    private function checkSession()
    {
        if (!$this->app['authentication']->getUser()) {
            throw new \RuntimeException('Phrasea currently support only authenticated queries');
        }

        if (!phrasea_open_session($this->app['session']->get('phrasea_session_id'), $this->app['authentication']->getUser()->getId())) {
            if (!$ses_id = phrasea_create_session((string) $this->app['authentication']->getUser()->getId())) {
                throw new \Exception_InternalServerError('Unable to create phrasea session');
            }
            $this->app['session']->set('phrasea_session_id', $ses_id);
        }

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getStatus()
    {
        $status = [];
        foreach (phrasea_info() as $key => $value) {
            $status[] = [$key, $value];
        }

        return $status;
    }

    /**
     * {@inheritdoc}
     */
    public function getConfigurationPanel()
    {
        if (!$this->configurationPanel) {
            $this->configurationPanel = new ConfigurationPanel($this, $this->app['conf']);
        }

        return $this->configurationPanel;
    }

    /**
     * {@inheritdoc}
     */
    public function getAvailableTypes()
    {
        return [self::GEM_TYPE_RECORD, self::GEM_TYPE_STORY];
    }

    /**
     * {@inheritdoc}
     */
    public function addRecord(\record_adapter $record)
    {
        return $this->updateRecord($record);
    }

    /**
     * {@inheritdoc}
     */
    public function removeRecord(\record_adapter $record)
    {
        $connbas = $record->get_databox()->get_connection();

        $sql = "DELETE FROM prop WHERE record_id = :record_id";
        $stmt = $connbas->prepare($sql);
        $stmt->execute([':record_id' => $record->get_record_id()]);
        $stmt->closeCursor();

        $sql = "DELETE FROM idx WHERE record_id = :record_id";
        $stmt = $connbas->prepare($sql);
        $stmt->execute([':record_id' => $record->get_record_id()]);
        $stmt->closeCursor();

        $sql = "DELETE FROM thit WHERE record_id = :record_id";
        $stmt = $connbas->prepare($sql);
        $stmt->execute([':record_id' => $record->get_record_id()]);
        $stmt->closeCursor();

        unset($stmt, $connbas);

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function updateRecord(\record_adapter $record)
    {
        $record->set_binary_status(\databox_status::dec2bin($this->app, bindec($record->get_status()) & ~7 | 4));

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function addStory(\record_adapter $record)
    {
        return $this->updateRecord($record);
    }

    /**
     * {@inheritdoc}
     */
    public function removeStory(\record_adapter $record)
    {
        return $this->removeRecord($record);
    }

    /**
     * {@inheritdoc}
     */
    public function updateStory(\record_adapter $record)
    {
        return $this->updateRecord($record);
    }

    /**
     * {@inheritdoc}
     */
    public function addFeedEntry(FeedEntry $entry)
    {
        throw new RuntimeException('Feed Entry indexing not supported by Phrasea Engine');
    }

    /**
     * {@inheritdoc}
     */
    public function removeFeedEntry(FeedEntry $entry)
    {
        throw new RuntimeException('Feed Entry indexing not supported by Phrasea Engine');
    }

    /**
     * {@inheritdoc}
     */
    public function updateFeedEntry(FeedEntry $entry)
    {
        throw new RuntimeException('Feed Entry indexing not supported by Phrasea Engine');
    }

    /**
     * {@inheritdoc}
     */
    public function query($query, $offset, $perPage, SearchEngineOptions $options = null)
    {
        if (null === $options) {
            $options = new SearchEngineOptions();
        }

        $this->initialize();
        $this->checkSession();
        $this->clearAllCache(new \DateTime('-1 hour'));

        assert(is_int($offset));
        assert($offset >= 0);
        assert(is_int($perPage));

        if (trim($query) === '') {
            $query = "all";
        }

        if ($options->getRecordType()) {
            $query .= ' AND recordtype=' . $options->getRecordType();
        }

        $sql = 'SELECT query, query_time, duration, total FROM cache WHERE session_id = :ses_id';
        $stmt = $this->app['phraseanet.appbox']->get_connection()->prepare($sql);
        $stmt->execute([':ses_id' => $this->app['session']->get('phrasea_session_id')]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        $date_obj = new \DateTime('-10 min');
        $date_quest = new \DateTime($row['query_time']);

        if ($query != $row['query']) {
            $this->resetCacheNextQuery = true;
        }
        if ($date_obj > $date_quest) {
            $this->resetCacheNextQuery = true;
        }

        if ($this->resetCacheNextQuery === true) {
            phrasea_clear_cache($this->app['session']->get('phrasea_session_id'));
            $this->addQuery($query, $options);
            $this->executeQuery($query, $options);

            $sql = 'SELECT query, query_time, duration, total FROM cache WHERE session_id = :ses_id';
            $stmt = $this->app['phraseanet.appbox']->get_connection()->prepare($sql);
            $stmt->execute([':ses_id' => $this->app['session']->get('phrasea_session_id')]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            $stmt->closeCursor();
        } else {
            /**
             * @todo clean this in DB
             */
            $this->total_available = $this->total_results = $this->app['session']->get('phrasea_engine_n_results');
        }

        $res = phrasea_fetch_results(
                $this->app['session']->get('phrasea_session_id'), $offset + 1, $perPage, false
        );

        $rs = [];
        $error = $this->app->trans('Unable to execute query');

        if (isset($res['results']) && is_array($res['results'])) {
            $rs = $res['results'];
            $error = '';
        }

        $resultNumber = $offset;
        $records = new ArrayCollection();

        foreach ($rs as $data) {
            try {
                $records->add(new \record_adapter(
                                $this->app,
                                \phrasea::sbasFromBas($this->app, $data['base_id']),
                                $data['record_id'],
                                $resultNumber
                ));
            } catch (\Exception $e) {

            }
            $resultNumber++;
        }

        $propositions = $this->getPropositions();
        $suggestions = $this->getSuggestions($query);

        return new SearchEngineResult($records, $query, $row['duration'], $offset, $row['total'], $row['total'], $error, '', $suggestions, $propositions, '');
    }

    /**
     * Returns suggestions for api
     *
     * @return ArrayCollection
     */
    private function getSuggestions($query)
    {
        $suggestions = [];

        if ($this->qp && isset($this->qp['main'])) {
            $suggestions = array_map(function ($value) use ($query) {
                return new SearchEngineSuggestion($query, $value['value'], $value['hits']);
            }, array_values($this->qp['main']->proposals['QUERIES']));
        }

        return new ArrayCollection($suggestions);
    }

    /**
     * Returns HTML Phrasea proposals
     *
     * @return string|null
     */
    private function getPropositions()
    {
        if ($this->qp && isset($this->qp['main'])) {
            $proposals = self::proposalsToHTML($this->app['translator'], $this->qp['main']->proposals);
            if (trim($proposals) !== '') {
                return "<div style='height:0px; overflow:hidden'>" . $this->qp['main']->proposals["QRY"]
                    . "</div><div class='proposals'>" . $proposals . "</div>";
            }
        }

        return null;
    }

    /**
     * Format proposals from QueryParser to HTML
     *
     * @param  array  $proposals
     * @return string
     */
    private static function proposalsToHTML(TranslatorInterface $translator, $proposals)
    {
        $html = '';
        $b = true;
        foreach ($proposals["BASES"] as $zbase) {
            if ((int) (count($proposals["BASES"]) > 1) && count($zbase["TERMS"]) > 0) {
                $style = $b ? 'style="margin-top:0px;"' : '';
                $b = false;
                $html .= "<h1 $style>" . $translator->trans('reponses::propositions pour la base %name', ['%name%' => $zbase["NAME"]]) . "</h1>";
            }
            $t = true;
            foreach ($zbase["TERMS"] as $path => $props) {
                $style = $t ? 'style="margin-top:0px;"' : '';
                $t = false;
                $html .= "<h2 $style>" . $translator->trans('reponses::propositions pour le terme %terme%', ['%terme%' => $props["TERM"]]) . "</h2>";
                $html .= $props["HTML"];
            }
        }

        return $html ;
    }

    /**
     * {@inheritdoc}
     *
     * @return PhraseaEngineSubscriber
     */
    public static function createSubscriber(Application $app)
    {
        return new PhraseaEngineSubscriber($app);
    }

    /**
     * {@inheritdoc}
     *
     * @return PhraseaEngine
     */
    public static function create(Application $app, array $options = [])
    {
        return new static($app);
    }

    /**
     * Executes the Phrasea query
     *
     * @param  string        $query
     * @return PhraseaEngine
     */
    private function executeQuery($query, SearchEngineOptions $options)
    {
        $nbanswers = $total_time = 0;
        $sort = '';

        if ($options->getSortBy()) {
            switch ($options->getSortOrder()) {
                case SearchEngineOptions::SORT_MODE_ASC:
                    $sort = '+';
                    break;
                case SearchEngineOptions::SORT_MODE_DESC:
                default:
                    $sort = '-';
                    break;
            }
            $sort .= '0' . $options->getSortBy();
        }

        foreach ($this->queries as $sbas_id => $qry) {
            $BF = [];

            foreach ($options->getBusinessFieldsOn() as $collection) {
                // limit business field query to databox local collection
                if ($sbas_id === $collection->get_sbas_id()) {
                    $BF[] = $collection->get_base_id();
                }
            }

            $results = phrasea_query2(
                    $this->app['session']->get('phrasea_session_id')
                    , $sbas_id
                    , $this->colls[$sbas_id]
                    , $this->arrayq[$sbas_id]
                    , $this->app['conf']->get(['main', 'key'])
                    , $this->app['session']->get('usr_id')
                    , false
                    , $options->getSearchType() == SearchEngineOptions::RECORD_GROUPING ? PHRASEA_MULTIDOC_REGONLY : PHRASEA_MULTIDOC_DOCONLY
                    , $sort
                    , $BF
                    , $options->isStemmed() ? $options->getLocale() : null
            );

            if ($results) {
                $total_time += $results['time_all'];
                $nbanswers += $results["nbanswers"];
            }
        }

        $sql = 'UPDATE cache
                SET query = :query, query_time = NOW(), duration = :duration, total = :total
                WHERE session_id = :ses_id';

        $params = [
            'query'     => $query,
            ':ses_id'   => $this->app['session']->get('phrasea_session_id'),
            ':duration' => $total_time,
            ':total'    => $nbanswers,
        ];

        $stmt = $this->app['phraseanet.appbox']->get_connection()->prepare($sql);
        $stmt->execute($params);
        $stmt->closeCursor();

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function autocomplete($query, SearchEngineOptions $options)
    {
        return new ArrayCollection();
    }

    /**
     * {@inheritdoc}
     */
    public function excerpt($query, $fields, \record_adapter $record, SearchEngineOptions $options = null)
    {
        if (null === $options) {
            $options = new SearchEngineOptions();
        }

        $ret = [];

        $this->initialize();
        $this->checkSession();

        $offset = $record->get_number() + 1;
        $res = phrasea_fetch_results(
            $this->app['session']->get('phrasea_session_id'), $offset, 1, true, "[[em]]", "[[/em]]"
        );

        if (!isset($res['results']) || !is_array($res['results'])) {
            return [];
        }

        $rs = $res['results'];
        $res = array_shift($rs);
        if (!isset($res['xml'])) {
            return [];
        }

        $sxe = @simplexml_load_string($res['xml']);

        foreach ($fields as $name => $field) {
            $newValues = [];
            if ($sxe && $sxe->description && $sxe->description->{$name}) {
                foreach ($sxe->description->{$name} as $value) {
                    $newValues[(string) $value['meta_id']] = (string) $value;
                }

                $ret[$name] = $newValues;
            }
        }

        return $ret;
    }

    /**
     * {@inheritdoc}
     */
    public function resetCache()
    {
        $this->resetCacheNextQuery = true;
        $this->queries = $this->arrayq = $this->colls = $this->qp = $this->needthesaurus = [];

        return $this;
    }

    /**
     * Prepares the query
     *
     * @param  string        $query
     * @return PhraseaEngine
     */
    private function addQuery($query, SearchEngineOptions $options)
    {
        foreach ($options->getDataboxes() as $databox) {
            $this->queries[$databox->get_sbas_id()] = $query;
        }

        $status = $options->getStatus();

        foreach ($this->queries as $sbas => $qs) {
            if ($status) {
                $requestStat = 'xxxx';

                for ($i = 4; ($i <= 32); $i++) {
                    if (!isset($status[$i])) {
                        $requestStat = 'x' . $requestStat;
                        continue;
                    }
                    $val = 'x';
                    if (isset($status[$i][$sbas])) {
                        if ($status[$i][$sbas] == '0') {
                            $val = '0';
                        } elseif ($status[$i][$sbas] == '1') {
                            $val = '1';
                        }
                    }
                    $requestStat = $val . $requestStat;
                }

                $requestStat = ltrim($requestStat, 'x');

                if ($requestStat !== '') {
                    $this->queries[$sbas] .= ' AND (recordstatus=' . $requestStat . ')';
                }
            }
            if ($options->getFields()) {
                $this->queries[$sbas] .= ' IN (' . implode(' OR ', array_map(function (\databox_field $field) {
                                            return $field->get_name();
                                        }, $options->getFields())) . ')';
            }
            if (($options->getMinDate() || $options->getMaxDate()) && $options->getDateFields()) {
                if ($options->getMinDate()) {
                    $this->queries[$sbas] .= ' AND ( ' . implode(' >= ' . $options->getMinDate()->format('Y-m-d') . ' OR  ', array_map(function (\databox_field $field) { return $field->get_name(); }, $options->getDateFields())) . ' >= ' . $options->getMinDate()->format('Y-m-d') . ' ) ';
                }
                if ($options->getMaxDate()) {
                    $this->queries[$sbas] .= ' AND ( ' . implode(' <= ' . $options->getMaxDate()->format('Y-m-d') . ' OR  ', array_map(function (\databox_field $field) { return $field->get_name(); }, $options->getDateFields())) . ' <= ' . $options->getMaxDate()->format('Y-m-d') . ' ) ';
                }
            }
        }

        $this->singleParse('main', $query, $options);

        foreach ($this->queries as $sbas => $db_query) {
            $this->singleParse($sbas, $this->queries[$sbas], $options);
        }

        $base_ids = array_map(function (\collection $collection) {
                        return $collection->get_base_id();
                    }, $options->getCollections());

        foreach ($options->getDataboxes() as $databox) {
            $sbas_id = $databox->get_sbas_id();

            $this->colls[$sbas_id] = [];

            foreach ($databox->get_collections() as $collection) {
                if (in_array($collection->get_base_id(), $base_ids)) {
                    $this->colls[$sbas_id][] = $collection->get_base_id();
                }
            }

            if (sizeof($this->colls[$sbas_id]) <= 0) {
                continue;
            }

            if ($this->needthesaurus[$sbas_id]) {
                if (($domth = $databox->get_dom_thesaurus())) {
                    $this->qp[$sbas_id]->thesaurus2($this->indep_treeq[$sbas_id], $sbas_id, $databox->get_dbname(), $domth, true);
                    $this->qp['main']->thesaurus2($this->indep_treeq['main'], $sbas_id, $databox->get_dbname(), $domth, true);
                }
            }

            $emptyw = false;

            $this->qp[$sbas_id]->set_default($this->indep_treeq[$sbas_id], $emptyw);
            $this->qp[$sbas_id]->distrib_in($this->indep_treeq[$sbas_id]);
            $this->qp[$sbas_id]->factor_or($this->indep_treeq[$sbas_id]);
            $this->qp[$sbas_id]->setNumValue($this->indep_treeq[$sbas_id], $databox->get_sxml_structure());
            $this->qp[$sbas_id]->thesaurus2_apply($this->indep_treeq[$sbas_id], $sbas_id);
            $this->arrayq[$sbas_id] = $this->qp[$sbas_id]->makequery($this->indep_treeq[$sbas_id]);
        }

        return $this;
    }

    /**
     * Parses the query for search engine
     *
     * @param  integer       $sbas
     * @param  string        $query
     * @return PhraseaEngine
     */
    private function singleParse($sbas, $query, SearchEngineOptions $options)
    {
        $this->qp[$sbas] = new PhraseaEngineQueryParser($this->app, $options->getLocale());
        $this->qp[$sbas]->debug = false;

        $simple_treeq = $this->qp[$sbas]->parsequery($query);

        $this->qp[$sbas]->priority_opk($simple_treeq);
        $this->qp[$sbas]->distrib_opk($simple_treeq);
        $this->needthesaurus[$sbas] = false;

        $this->indep_treeq[$sbas] = $this->qp[$sbas]->extendThesaurusOnTerms($simple_treeq, true, true, false);
        $this->needthesaurus[$sbas] = $this->qp[$sbas]->containsColonOperator($this->indep_treeq[$sbas]);

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function clearCache()
    {
        if ($this->app['session']->has('phrasea_session_id')) {
            $this->initialize();
            phrasea_close_session($this->app['session']->get('phrasea_session_id'));
            $this->app['session']->remove('phrasea_session_id');
        }

        return $this;
    }

    /**
     * @inheritdoc
     */
    public function clearAllCache(\DateTime $date = null)
    {
        if (!$date) {
            $date = new \DateTime();
        }

        $sql = "SELECT session_id FROM cache WHERE lastaccess <= :date";

        $stmt = $this->app['phraseanet.appbox']->get_connection()->prepare($sql);
        $stmt->execute([':date' => $date->format(DATE_ISO8601)]);
        $rs = $stmt->fetchAll(\PDO::FETCH_ASSOC);
        $stmt->closeCursor();

        foreach ($rs as $row) {
            phrasea_close_session($row['session_id']);
        }

        return $this;
    }

}
