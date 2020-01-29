<?php

namespace Completeness\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Json;
use Espo\ORM\EntityManager;
use Espo\ORM\IEntity;
use Espo\ORM\EntityCollection;
use PDO;
use Pim\Entities\Attribute;
use Pim\Entities\Channel;
use Pim\Entities\ProductAttributeValue;
use Treo\Core\Container;

/**
 * Class ProductCompleteness
 * @package Completeness\Services
 *
 * @author m.kokhanskyi <m.kokhanskyi@treolabs.com>
 */
class ProductCompleteness extends CommonCompleteness implements CompletenessInterface
{
    public const START_SORT_ORDER_CHANNEL = 200;

    /**
     * Update completeness for Product entity
     *
     * @param IEntity $entity
     * @return array
     * @throws Error
     */
    public function calculate(IEntity $entity): array
    {
        $entity = ($entity->getEntityType() === 'Product')
            ? $entity
            : $entity->get('product');

        $items = $this->prepareRequiredFields($entity);

        $completeness = parent::calculate($entity);

        $completeness['completeGlobal'] = $this->calculationCompleteGlobal($items['attrsGlobal'], $items['fields']);
        $channelsCompleteness
            = $this->calculationCompletenessChannel($items['fields'], $items['attrsChannel'], $entity);
        $completeness = array_merge($completeness, $channelsCompleteness);

        $this->setFieldsCompletenessInEntity($completeness, $entity);

        return array_merge($completeness, $completeness);
    }

    /**
     * @param string $entityName
     */
    public function afterDisable(string $entityName): void
    {
        $channels = $this
            ->getEntityManager()
            ->getRepository('Channel')
            ->select(['code'])
            ->find();

        foreach ($channels as $channel) {
            self::dropFieldChannel($this->getContainer(), $channel);
        }

        parent::afterDisable($entityName);
    }

    /**
     * @return array
     */
    public static function getCompleteField(): array
    {
        $fieldsComplete = [2 => 'completeGlobal'];

        $fields = parent::getCompleteField();

        $defs = self::CONFIG_COMPLETE_FIELDS;

        foreach ($fieldsComplete as $k => $field) {
            $defs['sortOrder'] = $k;
            $fields[$field] = $defs;
        }

        return $fields;
    }

    /**
     * @param Container $container
     * @param string $entity
     * @param bool $value
     */
    public static function setHasCompleteness(Container $container, string $entity, bool $value):void
    {
        parent::setHasCompleteness($container, $entity, $value);

        /** @var Channel[] $channels */
        $channels = $container
            ->get('entityManager')
            ->getRepository('Channel')
            ->select(['name', 'code'])
            ->find();

        $defs = self::CONFIG_COMPLETE_FIELDS;
        $defs['isChannel'] = true;
        $defs['isCustom'] = false;

        foreach ($channels as $k => $ch) {
            $defs['sortOrder'] = self::START_SORT_ORDER_CHANNEL + (int)$k;
            self::createFieldChannel($container, $ch, $defs);
        }

        //set HasCompleteness for ProductAttributeValue
        parent::setHasCompleteness($container, 'ProductAttributeValue', $value);
    }

    /**
     * @param Channel $channel
     * @return string
     */
    public static function getLabelChannelField(Channel $channel): string
    {
        return $channel->get('name');
    }

    /**
     * @param Channel $channel
     * @return string
     */
    public static function getNameChannelField(Channel $channel): string
    {
        return 'completeness_channel_' . $channel->get('code');
    }

    /**
     * @param Container $container
     * @param Channel $channel
     * @param array $defs
     * @param bool $notRebuild
     */
    public static function createFieldChannel(Container $container, Channel $channel, array $defs, bool $notRebuild = true)
    {
        $defs['label'] = empty($defs['label']) ? self::getLabelChannelField($channel) : $defs['label'];
        $defs['notNull'] = empty($defs['notNull']) ? true : $defs['notNull'];
        $defs['default'] = empty($defs['default']) ? null : $defs['default'];

        $nameField = self::getNameChannelField($channel);

        if (empty($container->get('metadata')->get(['entityDefs', 'Product', 'fields', $nameField]))) {
            $container->get('fieldManager')->create('Product', $nameField, $defs);

            if (!$notRebuild) {
                $container->get('dataManager')->rebuild();
            }
        }
    }

    /**
     * @param Container $container
     * @param Channel $channel
     * @param bool $notRebuild
     */
    public static function dropFieldChannel(Container $container, Channel $channel, bool $notRebuild = true): void
    {
        $nameField = self::getNameChannelField($channel);

        if (!empty($container->get('metadata')->get(['entityDefs', 'Product', 'fields', $nameField]))) {
            $container
                ->get('fieldManager')
                ->delete('Product', $nameField);

            if (!$notRebuild) {
                $container->get('dataManager')->rebuild();
            }

            self::dropColumnWithTable($container->get('entityManager'), $nameField);
        }
    }

    /**
     * @param EntityManager $em
     * @param $column
     */
    public static function dropColumnWithTable(EntityManager $em, string $column): void
    {
        $columns = $em->nativeQuery("SHOW COLUMNS FROM `product` LIKE '{$column}'")->fetch(PDO::FETCH_ASSOC);
        if (!empty($columns)) {
            $em ->getPDO()->exec('ALTER TABLE product DROP COLUMN ' . $column);
        }
    }

    /**
     * @param IEntity $entity
     * @return array
     * @throws Error
     */
    protected function prepareRequiredFields(IEntity $entity): array
    {
        $result = parent::prepareRequiredFields($entity);

        return array_merge_recursive($result, $this->prepareRequiredAttr($entity));
    }

    /**
     * Prepare required attributes
     * @param IEntity $entity
     * @return array
     */
    protected function prepareRequiredAttr(IEntity $entity): array
    {
        // get required attributes
        $attributes = $this->getAttrs($entity);

        $result = [
            'fields' => [],
            'multiLang' => [],
            'localComplete' => [],
            'attrsGlobal' => [],
            'completeTotal' => [],
            'attrsChannel' => []
        ];

        /** @var Attribute $attr */
        foreach ($attributes as $attr) {
            $scope = $attr->get('scope');

            $isEmpty = $this->isEmpty($attr, $entity);
            $item = ['id' => $attr->get('id'), 'isEmpty' => $isEmpty];

            $result['localComplete'][] = $item;
            $result['completeTotal'][] = $isEmpty;
            if ($scope === 'Global') {
                $result['attrsGlobal'][] = $item;
            } elseif ($scope === 'Channel') {
                $channels = $attr->get('channels')->toArray();
                $channels = !empty($channels) ? array_column($channels, 'id') : [];
                $this->setItemByChannel($channels, $item, $result);
            }
            if (!empty($attr->get('attribute')->get('isMultilang'))) {
                foreach ($this->getLanguages() as $local => $language) {
                    $isEmpty = $this->isEmpty($attr, $entity, $language);
                    $item = ['id' => $attr->get('id'), 'isEmpty' => $isEmpty, 'isMultiLang' => true];

                    $result['multiLang'][$local][] = $item;
                    $result['completeTotal'][] = $isEmpty;
                    if ($scope === 'Global') {
                        $result['attrsGlobal'][] = $item;
                    } elseif ($scope === 'Channel') {
                        $this->setItemByChannel($channels, $item, $result);
                    }
                }
            }
        }

        return $result;
    }

    /**
     * @param array $fields
     * @param array $attrsChannel
     * @return array
     */
    protected function calculationCompletenessChannel(array $fields, array $attrsChannel, IEntity $entity): array
    {
        $completenessChannels = [];
        foreach ($this->getChannels($entity) as $channel) {
            $id = $channel['id'];
            if (is_array($attrsChannel[$id])) {
                //channels attributes + fields
                $items = array_merge($attrsChannel[$id], $fields);
            } else {
                $items = $fields;
            }
            $completenessChannels['completeness_channel_' . $channel['code']] =  $this->commonCalculationComplete($items);
        }
        return $completenessChannels;
    }

    /**
     * @param array $attrsGlobal
     * @param array $fields
     * @return float
     */
    protected function calculationCompleteGlobal(array $attrsGlobal, array $fields): float
    {
        $globalItems = array_merge($attrsGlobal, $fields);
        return $this->commonCalculationComplete($globalItems);
    }

    /**
     * @param EntityCollection $attributes
     *
     * @param IEntity $entity
     * @return EntityCollection
     */
    protected function filterAttributes(EntityCollection $attributes, IEntity $entity): EntityCollection
    {
        if (count($attributes) > 0 && $entity->get('type') === 'configurableProduct') {
            foreach ($attributes as $k => $attribute) {
                if (in_array($attribute->get('id'), $this->getExcludedAttributes($entity), true)) {
                    $attributes->offsetUnset($k);
                }
            }
        }
        return $attributes;
    }

    /**
     * @param mixed $value
     * @param string $language
     *
     * @param IEntity $entity
     * @return bool
     */
    protected function isEmpty($value, IEntity $entity, string $language = ''): bool
    {
        $isEmpty = true;
        if (is_string($value) && !empty($valueCurrent = $entity->get($value . $language))) {
            if ($valueCurrent instanceof EntityCollection) {
                $isEmpty = (bool)$valueCurrent->count();
            } else {
                $isEmpty = false;
            }
        } elseif ($value instanceof ProductAttributeValue) {
            if (in_array($value->get('attribute')->get('type'), ['array', 'multiEnum'])) {
                $attributeValue = Json::decode($value->get('value' . $language), true);
            } else {
                $attributeValue = $value->get('value' . $language);
            }
            $isEmpty = empty($attributeValue);
        }
        return $isEmpty;
    }

    /**
     * @param IEntity $entity
     * @return EntityCollection|null
     */
    protected function getAttrs(IEntity $entity): EntityCollection
    {
        $attributes = $this->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->leftJoin(['productFamilyAttribute', 'attribute'])
            ->where([
                'productId' => $entity->get('id'),
                'productFamilyAttribute.isRequired' => true
            ])
            ->find();

        return $this->filterAttributes($attributes, $entity);
    }

    /**
     * @param IEntity $entity
     * @return array
     */
    protected function getChannels(IEntity $entity): array
    {
        if ($entity->get('type') === 'productVariant'
            && !empty($entity->get('configurableProduct'))
            && !in_array('channels', $entity->get('data')->customRelations, true)) {
            $channels = $entity->get('configurableProduct')->get('channels')->toArray();
        } else {
            $channels = $entity->get('channels')->toArray();
        }
        return !empty($channels) ? $channels : [];
    }

    /**
     * @param IEntity $entity
     * @return array
     */
    protected function getExcludedAttributes(IEntity $entity): array
    {
        $result = [];
        if ($entity->get('type') === 'configurableProduct') {
            $variants = $entity->get('productVariants');
            if (!empty($variants) && count($variants) > 0) {
                /** @var IEntity $variant */
                foreach ($variants as $variant) {
                    $result = array_merge($result, array_column($variant->get('data')->attributes, 'id'));
                }
                $result = array_unique($result);
            }
        }
        return $result;
    }

    /**
     * @param array $channels
     * @param array $item
     * @param array $result
     */
    private function setItemByChannel(array $channels, array $item, array &$result): void
    {
        foreach ($channels as $channel) {
            $result['attrsChannel'][$channel][] = $item;
        }
    }
}
