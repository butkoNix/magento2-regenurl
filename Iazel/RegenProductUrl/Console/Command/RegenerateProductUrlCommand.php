<?php
namespace Iazel\RegenProductUrl\Console\Command;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;
use Magento\UrlRewrite\Model\UrlPersistInterface;
use Magento\CatalogUrlRewrite\Model\ProductUrlRewriteGenerator;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Store\Model\Store;
use Magento\Framework\App\State;
use Magento\Framework\App\ResourceConnection;
use Magento\Catalog\Model\Product;
use Magento\Eav\Model\Config;

class RegenerateProductUrlCommand extends Command
{
    /**
     * @var ProductUrlRewriteGenerator
     */
    protected $productUrlRewriteGenerator;

    /**
     * @var UrlPersistInterface
     */
    protected $urlPersist;

    /**
     * @var ProductRepositoryInterface
     */
    protected $collection;

    /**
     * @var \Magento\Framework\App\State
     */
    protected $state;

    /**
     * @var ResourceConnection
     */
    protected $resource;

    /**
     * @var Config
     */
    protected $eavConfig;

    public function __construct(
        State $state,
        Collection $collection,
        ProductUrlRewriteGenerator $productUrlRewriteGenerator,
        UrlPersistInterface $urlPersist,
        ResourceConnection $resource,
        Config $eavConfig
    ) {
        $this->state = $state;
        $this->collection = $collection;
        $this->productUrlRewriteGenerator = $productUrlRewriteGenerator;
        $this->urlPersist = $urlPersist;
        $this->resource = $resource;
        $this->eavConfig = $eavConfig;
        parent::__construct();
    }

    protected function configure()
    {
        $this->setName('iazel:regenurl')
            ->setDescription('Regenerate url for given products')
            ->addArgument(
                'pids',
                InputArgument::IS_ARRAY,
                'Products to regenerate'
            )
            ->addOption(
                'store', 's',
                InputOption::VALUE_REQUIRED,
                'Use the specific Store View',
                Store::DEFAULT_STORE_ID
            )
            ;
        return parent::configure();
    }

    public function execute(InputInterface $inp, OutputInterface $out)
    {
        if (!$this->state->getAreaCode()) {
            $this->state->setAreaCode('adminhtml');
        }

        $store_id = $inp->getOption('store');
        $this->collection->addStoreFilter($store_id)->setStoreId($store_id);

        $this->cleanStoreUrls($store_id);

        $pids = $inp->getArgument('pids');
        if( !empty($pids) )
            $this->collection->addIdFilter($pids);

        $this->collection->addAttributeToSelect(['url_path', 'url_key']);
        $list = $this->collection->load();
        foreach($list as $product)
        {
            if($store_id === Store::DEFAULT_STORE_ID)
                $product->setStoreId($store_id);

            $this->urlPersist->deleteByData([
                UrlRewrite::ENTITY_ID => $product->getId(),
                UrlRewrite::ENTITY_TYPE => ProductUrlRewriteGenerator::ENTITY_TYPE,
                UrlRewrite::REDIRECT_TYPE => 0,
                UrlRewrite::STORE_ID => $store_id
            ]);
            try {
                $this->urlPersist->replace(
                    $this->productUrlRewriteGenerator->generate($product)
                );
            }
            catch(\Exception $e) {
                $out->writeln('<error>Duplicated url for '. $product->getId() .'</error>');
            }
        }
    }

    private function cleanStoreUrls($store_id)
    {
        $connection = $this->resource->getConnection();
        $attributeId = $this->eavConfig->getAttribute(Product::ENTITY, 'url_key')->getId();
        if (is_string($store_id)) {
            $connection->query("DELETE FROM `catalog_product_entity_varchar` WHERE `attribute_id`='" . $attributeId . "' AND `store_id`='" . $store_id . "';");
        } else {
            $connection->query("DELETE FROM `catalog_product_entity_varchar` WHERE `attribute_id`='" . $attributeId . "' AND `store_id` NOT IN ('0');");
        }
    }
}
