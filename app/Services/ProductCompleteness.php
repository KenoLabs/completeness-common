<?php

namespace Completeness\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Json;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Pim\Entities\Attribute;
use Pim\Entities\ProductAttributeValue;

/**
 * Class ProductCompleteness
 * @package Completeness\Services
 *
 * @author m.kokhanskyi <m.kokhanskyi@treolabs.com>
 */
class ProductCompleteness extends CommonCompleteness implements CompletenessInterface
{

    const CONFIG_FIELD_CHANNELS_DATA = [
        'type' => 'jsonObject',
        'layoutDetailDisabled' => true,
        'layoutListDisabled' => true,
        "importDisabled" => true,
    ];

    /**
     * @param Entity $entity
     */
    public function setEntity(Entity $entity): void
    {
        $this->entity = ($entity->getEntityType() == 'Product')
            ? $entity
            : $entity->get('product');
    }

    /**
     * Update completeness for Product entity
     *
     * @return array
     * @throws Error
     */
    public function calculate(): array
    {
        $this->prepareRequiredAttr();

        $result = parent::calculate();

        $completeness['completeGlobal'] = $this->calculationCompleteGlobal();
        $channelCompleteness = $this->calculationCompletenessChannel();
        $completeness['channelCompleteness'] = [
            'total' => count($channelCompleteness),
            'list' => $channelCompleteness];

        $this->setFieldsCompletenessInEntity($completeness);

        return array_merge($completeness, $result);
    }

    /**
     * @return array
     */
    public static function getCompleteField(): array
    {
        $fieldsComplete = ['completeGlobal'];
        $fields = parent::getCompleteField();
        foreach ($fieldsComplete as $field) {
            $defs = self::CONFIG_COMPLETE_FIELDS;
            $fields[$field] = $defs;
        }
        $fields['channelCompleteness'] = self::CONFIG_FIELD_CHANNELS_DATA;
        return $fields;
    }

    /**
     * Prepare required attributes
     */
    protected function prepareRequiredAttr(): void
    {
        // get required attributes
        $attributes = $this->getAttrs();

        /** @var Attribute $attr */
        foreach ($attributes as $attr) {
            $scope = $attr->get('scope');

            $isEmpty = $this->isEmpty($attr);
            $item = ['id' => $attr->get('id'), 'isEmpty' => $isEmpty];

            $this->items['localComplete'][] = $item;
            $this->itemsForTotalComplete[] = $isEmpty;
            if ($scope == 'Global') {
                $this->items['attrsGlobal'][] = $item;
            } elseif ($scope == 'Channel') {
                $channels = $attr->get('channels')->toArray();
                $channels = !empty($channels) ? array_column($channels, 'id') : [];
                $this->setItemByChannel($channels, $item);
            }
            if (!empty($attr->get('attribute')->get('isMultilang'))) {
                foreach ($this->getLanguages() as $local => $language) {
                    $isEmpty = $this->isEmpty($attr, $language);
                    $item = ['id' => $attr->get('id'), 'isEmpty' => $isEmpty, 'isMultiLang' => true];

                    $this->items['multiLang'][$local][] = $item;
                    $this->itemsForTotalComplete[] = $isEmpty;
                    if ($scope == 'Global') {
                        $this->items['attrsGlobal'][] = $item;
                    } elseif ($scope == 'Channel') {
                        $this->setItemByChannel($channels, $item);
                    }
                }
            }
        }
    }

    /**
     * @return array
     */
    protected function calculationCompletenessChannel(): array
    {
        $completenessChannels = [];
        foreach ($this->getChannels() as $channel) {
            $id = $channel['id'];
            if (is_array($this->getItem('attrsChannel')[$id])) {
                //channels attributes + fields
                $items = array_merge($this->getItem('attrsChannel')[$id], $this->getItem('fields'));
            } else {
                $items = $this->getItem('fields');
            }
            $completenessChannels[] = [
                'id' => $id,
                'name' => $channel['name'],
                'complete' => $this->commonCalculationComplete($items)];
        }
        return $completenessChannels;
    }

    /**
     * @return float
     */
    protected function calculationCompleteGlobal(): float
    {
        $globalItems = array_merge($this->getItem('attrsGlobal'), $this->getItem('fields'));
        return $this->commonCalculationComplete($globalItems);
    }

    /**
     * @param EntityCollection $attributes
     *
     * @return EntityCollection
     */
    protected function filterAttributes(EntityCollection $attributes): EntityCollection
    {
        if (count($attributes) > 0 && $this->entity->get('type') == 'configurableProduct') {
            foreach ($attributes as $k => $attribute) {
                if (in_array($attribute->get('id'), $this->getExcludedAttributes())) {
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
     * @return bool
     */
    protected function isEmpty($value, string $language = ''): bool
    {
        $isEmpty = true;
        if (is_string($value) && !empty($valueCurrent = $this->entity->get($value . $language))) {
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
     * @return EntityCollection|null
     */
    protected function getAttrs(): EntityCollection
    {
        $attributes = $this->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->leftJoin(['productFamilyAttribute', 'attribute'])
            ->where([
                'productId' => $this->entity->get('id'),
                'productFamilyAttribute.isRequired' => true
            ])
            ->find();

        return $this->filterAttributes($attributes);
    }

    /**
     * @return array
     */
    protected function getChannels(): array
    {
        if ($this->entity->get('type') == 'productVariant'
            && !in_array('channels', $this->entity->get('data')->customRelations)) {
            $channels = $this->entity->get('configurableProduct')->get('channels')->toArray();
        } else {
            $channels = $this->entity->get('channels')->toArray();
        }
        return !empty($channels) ? $channels : [];
    }

    /**
     * @return array
     */
    protected function getExcludedAttributes(): array
    {
        $result = [];
        if ($this->entity->get('type') == 'configurableProduct') {
            $variants = $this->entity->get('productVariants');
            if (count($variants) > 0) {
                /** @var Entity $variant */
                foreach ($variants as $variant) {
                    $result = array_merge($result, array_column($variant->get('data')->attributes, 'id'));
                }
                $result = array_unique($result);
            }
        }
        return $result;
    }

    /**
     * @param $channels
     * @param $item
     */
    private function setItemByChannel(array $channels, array $item): void
    {
        foreach ($channels as $channel) {
            $this->items['attrsChannel'][$channel][] = $item;
        }
    }
}
