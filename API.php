<?php
/**
 * Piwik - free/libre analytics platform
 *
 * @link http://piwik.org
 * @license http://www.gnu.org/licenses/gpl-3.0.html GPL v3 or later
 *
 */
namespace Piwik\Plugins\CustomDimensions;

use Piwik\DataTable\Row;

use Piwik\Archive;
use Piwik\DataTable;
use Piwik\Metrics;
use Piwik\Piwik;
use Piwik\Plugins\CustomDimensions\Dao\Configuration;
use Piwik\Plugins\CustomDimensions\Dao\LogTable;
use Piwik\Plugins\CustomDimensions\Dimension\Active;
use Piwik\Plugins\CustomDimensions\Dimension\Dimension;
use Piwik\Plugins\CustomDimensions\Dimension\Extraction;
use Piwik\Plugins\CustomDimensions\Dimension\Extractions;
use Piwik\Plugins\CustomDimensions\Dimension\Index;
use Piwik\Plugins\CustomDimensions\Dimension\Name;
use Piwik\Plugins\CustomDimensions\Dimension\Scope;
use Piwik\Tracker\Cache;

/**
 * The Custom Dimensions API lets you manage and access reports for your configured Custom Dimensions.
 *
 * @method static API getInstance()
 */
class API extends \Piwik\Plugin\API
{

    /**
     * Fetch a report for the given idDimension. Only reports for active dimensions can be fetched. Requires at least
     * view access.
     *
     * @param $idDimension
     * @param $idSite
     * @param $period
     * @param $date
     * @param bool|false $segment
     * @return DataTable|DataTable\Map
     * @throws \Exception
     */
    public function getCustomDimension($idDimension, $idSite, $period, $date, $segment = false, $expanded = false, $idSubtable = null)
    {
        Piwik::checkUserHasViewAccess($idSite);

        $dimension = new Dimension($idDimension, $idSite);
        $dimension->checkActive();

        $record = Archiver::buildRecordNameForCustomDimensionId($idDimension);

        $dataTable = Archive::createDataTableFromArchive($record, $idSite, $period, $date, $segment, $expanded, $flat = false, $idSubtable);

        if (isset($idSubtable) && $dataTable->getRowsCount()) {
            $parentTable = Archive::createDataTableFromArchive($record, $idSite, $period, $date, $segment);
            foreach ($parentTable->getRows() as $row) {
                if ($row->getIdSubDataTable() == $idSubtable) {
                    $parentValue = $row->getColumn('label');
                    $dataTable->queueFilter('Piwik\Plugins\CustomDimensions\DataTable\Filter\AddSubtableSegmentMetadata', array($idDimension, $parentValue));
                    break;
                }
            }
        } else {
            $dataTable->queueFilter('Piwik\Plugins\CustomDimensions\DataTable\Filter\AddSegmentMetadata', array($idDimension));
        }

        $dataTable->filter('Piwik\Plugins\CustomDimensions\DataTable\Filter\RemoveUserIfNeeded', array($idSite, $period, $date));

        return $dataTable;
    }

    /**
     * Configures a new Custom Dimension. Note that Custom Dimensions cannot be deleted, be careful when creating one
     * as you might run quickly out of available Custom Dimension slots. Requires at least Admin access for the
     * specified website. A current list of available `$scopes` can be fetched via the API method
     * `CustomDimensions.getAvailableScopes()`. This method will also contain information whether actually Custom
     * Dimension slots are available or whether they are all already in use.
     *
     * @param int $idSite    The idSite the dimension shall belong to
     * @param string $name   The name of the dimension
     * @param string $scope  Either 'visit' or 'action'. To get an up to date list of availabe scopes fetch the
     *                       API method `CustomDimensions.getAvailableScopes`
     * @param int $active  '0' if dimension should be inactive, '1' if dimension should be active
     * @param array $extractions    Either an empty array or if extractions shall be used one or multiple extractions
     *                              the format array(array('dimension' => 'url', 'pattern' => 'index_(.+).html'), array('dimension' => 'urlparam', 'pattern' => '...'))
     *                              Supported dimensions are  eg 'url', 'urlparam' and 'action_name'. To get an up to date list of
     *                              supported dimensions request the API method `CustomDimensions.getAvailableExtractionDimensions`
     * @return int Returns the ID of the configured dimension. Note that the same idDimension will be used for different websites.
     * @throws \Exception
     */
    public function configureNewCustomDimension($idSite, $name, $scope, $active, $extractions = array())
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $this->validateCustomDimensionConfig($name, $active, $extractions);

        $scopeCheck = new Scope($scope);
        $scopeCheck->check();

        $index = new Index();
        $index = $index->getNextIndex($idSite, $scope);

        $configuration = $this->getConfiguration();
        $idDimension   = $configuration->configureNewDimension($idSite, $name, $scope, $index, $active, $extractions);

        Cache::clearWebsiteCache($idSite);
        Cache::clearCacheGeneral();

        return $idDimension;
    }

    /**
     * Updates an existing Custom Dimension. This method updates all values, you need to pass existing values of the
     * dimension if you do not want to reset any value. Requires at least Admin access for the specified website.
     *
     * @param int $idDimension  The id of a Custom Dimension.
     * @param int $idSite       The idSite the dimension belongs to
     * @param string $name      The name of the dimension
     * @param int $active       '0' if dimension should be inactive, '1' if dimension should be active
     * @param array $extractions    Either an empty array or if extractions shall be used one or multiple extractions
     *                              the format array(array('dimension' => 'url', 'pattern' => 'index_(.+).html'), array('dimension' => 'urlparam', 'pattern' => '...'))
     *                              Supported dimensions are  eg 'url', 'urlparam' and 'action_name'. To get an up to date list of
     *                              supported dimensions request the API method `CustomDimensions.getAvailableExtractionDimensions`
     * @return int Returns the ID of the configured dimension. Note that the same idDimension will be used for different websites.
     * @throws \Exception
     */
    public function configureExistingCustomDimension($idDimension, $idSite, $name, $active, $extractions = array())
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $dimension = new Dimension($idDimension, $idSite);
        $dimension->checkExists();

        $this->validateCustomDimensionConfig($name, $active, $extractions);

        $this->getConfiguration()->configureExistingDimension($idDimension, $idSite, $name, $active, $extractions);

        Cache::clearWebsiteCache($idSite);
        Cache::clearCacheGeneral();
    }

    /**
     * Get a list of all configured CustomDimensions for a given website. Requires at least Admin access for the
     * specified website.
     *
     * @param int $idSite
     * @return array
     */
    public function getConfiguredCustomDimensions($idSite)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $configs = $this->getConfiguration()->getCustomDimensionsForSite($idSite);

        return $configs;
    }

    private function validateCustomDimensionConfig($name, $active, $extractions)
    {
        // ideally we would work with these objects a bit more instead of arrays but we'd have a lot of
        // serialize/unserialize to do as we need to cache all configured custom dimensions for tracker cache and
        // we do not want to serialize all php instances there. Also we need to return an array for each
        // configured dimension in API methods anyway

        $name = new Name($name);
        $name->check();

        $active = new Active($active);
        $active->check();

        $extractions = new Extractions($extractions);
        $extractions->check();
    }

    /**
     * Get a list of all supported scopes that can be used in the API method
     * `CustomDimensions.configureNewCustomDimension`. The response also contains information whether more Custom
     * Dimensions can be created or not. Requires at least Admin access for the specified website.
     *
     * @param int $idSite
     * @return array
     */
    public function getAvailableScopes($idSite)
    {
        Piwik::checkUserHasAdminAccess($idSite);

        $scopes = array();
        foreach (CustomDimensions::getPublicScopes() as $scope) {

            $configs = $this->getConfiguration()->getCustomDimensionsHavingScope($idSite, $scope);
            $indexes = $this->getTracking($scope)->getInstalledIndexes();

            $scopes[] = array(
                'name' => $scope,
                'numSlotsAvailable' => count($indexes),
                'numSlotsUsed' => count($configs),
                'numSlotsLeft' => count($indexes) - count($configs)
            );
        }

        return $scopes;
    }

    /**
     * Get a list of all available dimensions that can be used in an extraction. Requires at least Admin access
     * to one website.
     *
     * @return array
     */
    public function getAvailableExtractionDimensions()
    {
        Piwik::checkUserHasSomeAdminAccess();

        $supported = Extraction::getSupportedDimensions();

        $dimensions = array();
        foreach ($supported as $value => $dimension) {
            $dimensions[] = array('value' => $value, 'name' => $dimension);
        }

        return $dimensions;
    }

    private function getTracking($scope)
    {
        return new LogTable($scope);
    }

    private function getConfiguration()
    {
        return new Configuration();
    }

}

