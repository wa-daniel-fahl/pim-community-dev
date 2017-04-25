<?php

namespace Pim\Bundle\CatalogBundle\Doctrine\Common\Saver;

use Akeneo\Component\StorageUtils\Saver\BulkSaverInterface;
use Akeneo\Component\StorageUtils\Saver\SaverInterface;
use Akeneo\Component\StorageUtils\StorageEvents;
use Doctrine\Common\Persistence\ObjectManager;
use Doctrine\Common\Util\ClassUtils;
use Pim\Component\Catalog\Manager\CompletenessManager;
use Pim\Component\Catalog\Model\ProductInterface;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\EventDispatcher\GenericEvent;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Process\PhpExecutableFinder;

/**
 * Product saver, define custom logic and options for product saving
 *
 * @author    Nicolas Dupont <nicolas@akeneo.com>
 * @copyright 2014 Akeneo SAS (http://www.akeneo.com)
 * @license   http://opensource.org/licenses/osl-3.0.php  Open Software License (OSL 3.0)
 */
class ProductSaver implements SaverInterface, BulkSaverInterface
{
    /** @var ObjectManager */
    protected $objectManager;

    /** @var CompletenessManager */
    protected $completenessManager;

    /** @var EventDispatcherInterface */
    protected $eventDispatcher;
    /**
     * @var Kernel
     */
    private $kernel;

    /** @var string */
    protected $rootDir;
    /**
     * @var
     */
    private $user;

    /**
     * @param ObjectManager            $om
     * @param CompletenessManager      $completenessManager
     * @param EventDispatcherInterface $eventDispatcher
     * @param Kernel                   $kernel
     */
    public function __construct(
        ObjectManager $om,
        CompletenessManager $completenessManager,
        EventDispatcherInterface $eventDispatcher,
        Kernel $kernel,
        $rootDir,
        $user
    ) {
        $this->objectManager = $om;
        $this->completenessManager = $completenessManager;
        $this->eventDispatcher = $eventDispatcher;
        $this->kernel = $kernel;
        $this->rootDir = $rootDir;
        $this->user = $user;
    }

    /**
     * {@inheritdoc}
     */
    public function save($product, array $options = [])
    {
        $this->validateProduct($product);

        $options['unitary'] = true;

        $this->eventDispatcher->dispatch(StorageEvents::PRE_SAVE, new GenericEvent($product, $options));

        $this->completenessManager->schedule($product);

        $this->objectManager->persist($product);
        $this->objectManager->flush();
        $this->runSynchroCommand($product->getIdentifier());

        $this->completenessManager->generateMissingForProduct($product);

        $this->eventDispatcher->dispatch(StorageEvents::POST_SAVE, new GenericEvent($product, $options));
    }

    /**
     * {@inheritdoc}
     */
    public function saveAll(array $products, array $options = [])
    {
        if (empty($products)) {
            return;
        }

        $options['unitary'] = false;

        $this->eventDispatcher->dispatch(StorageEvents::PRE_SAVE_ALL, new GenericEvent($products, $options));

        foreach ($products as $product) {
            $this->validateProduct($product);

            $this->eventDispatcher->dispatch(StorageEvents::PRE_SAVE, new GenericEvent($product, $options));

            $this->completenessManager->schedule($product);

            $this->objectManager->persist($product);
        }

        $this->objectManager->flush();

        foreach ($products as $product) {
            $this->completenessManager->generateMissingForProduct($product);

            $this->runSynchroCommand($product->getIdentifier(), $options);

            $this->eventDispatcher->dispatch(StorageEvents::POST_SAVE, new GenericEvent($product, $options));
        }

        $this->eventDispatcher->dispatch(StorageEvents::POST_SAVE_ALL, new GenericEvent($products, $options));
    }

    /**
     * @param $product
     */
    protected function validateProduct($product)
    {
        if (!$product instanceof ProductInterface) {
            throw new \InvalidArgumentException(
                sprintf(
                    'Expects a Pim\Component\Catalog\Model\ProductInterface, "%s" provided',
                    ClassUtils::getClass($product)
                )
            );
        }
    }

    private function runSynchroCommand($productIdentifier, array $option = [])
    {
        $pathFinder = new PhpExecutableFinder();
        if(null !== $this->user->getToken()) {
            $username = $this->user->getToken()->getUser()->getUsername();
            $cmd =
                sprintf(
                    '%s %s/console pim:synchro push %s supplier %s',
                    $pathFinder->find(),
                    $this->rootDir,
                    $username,
                    $productIdentifier
                );
            exec($cmd);
        }

        if(!empty($option)) {
            $username = $option['username'];
            $cmd =
                sprintf(
                    '%s %s/console pim:synchro push %s supplier %s',
                    $pathFinder->find(),
                    $this->rootDir,
                    $username,
                    $productIdentifier
                );

            exec($cmd);
        }
    }
}
