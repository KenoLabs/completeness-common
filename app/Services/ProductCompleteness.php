<?php

namespace Completeness\Services;

use Espo\Core\Exceptions\Error;
use Espo\Core\Utils\Json;
use Espo\ORM\Entity;
use Espo\ORM\EntityCollection;
use Pim\Entities\Attribute;

/**
 * Class ProductCompleteness
 * @package Completeness\Services
 *
 * @author m.kokhanskyi <m.kokhanskyi@treolabs.com>
 */
class ProductCompleteness extends Completeness implements CompletenessInterface
{
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
    public function run(): array
    {
        $this->prepareRequiredAttr();

        $result = $this->runUpdateCommonCompleteness();
        $completeness['completeGlobal'] = $this->calculationCompleteGlobal();
        $channelCompleteness = $this->calculationCompletenessChannel();
        $completeness['channelCompleteness'] = [
            'total' => count($channelCompleteness),
            'list' => $channelCompleteness ];

        $this->setFieldsCompletenessInEntity($completeness);
        return array_merge($completeness, $result);
    }

    /**
     * @return array
     */
    protected function calculationCompletenessChannel(): array
    {
       $completenessChannels = [];
        if ($this->getConfig()->get('isMultilangActive')) {
            foreach ($this->getChannels() as $channel) {
                if (is_array($this->fieldsAndAttrs['attrsChannel'][$channel])) {
                    $items = array_merge($this->fieldsAndAttrs['attrsChannel'][$channel], $this->fieldsAndAttrs['fields']);
                } else {
                    $items = $this->fieldsAndAttrs['fields'];
                }
                $completenessChannels[$channel] = $this->commonCalculationComplete($items);
            }
        }
        return $completenessChannels;
    }

    /**
     * Prepare required attributes
     */
    protected function prepareRequiredAttr(): void
    {
        // get required attributes
        $attributes = $this->getAttrs();

        $attributes = $this->filterAttributes($attributes);
        $multiLangFields = $this
            ->getContainer()
            ->get('metadata')->get('multilang.multilangFields', []);

        /** @var Attribute $attr */
        foreach ($attributes as $attr) {
            $scope = $attr->get('scope');

            $isEmpty = $this->isEmpty($attr);
            $item = ['id' => $attr->get('id'), 'isEmpty' => $isEmpty];

            $this->fieldsAndAttrs['localComplete'][] = $item;
            $this->fieldsForTotalComplete[] = $isEmpty;
            if ($scope == 'Global') {
                $this->fieldsAndAttrs['attrsGlobal'][] = $item;
            } elseif ($scope == 'Channel') {
                $channels = $attr->get('channels')->toArray();
                $channels = !empty($channels) ? array_column($channels, 'id') : [];
                $this->setItemByChannel($channels, $item);
            }
            if (isset($multiLangFields[$attr->get('attribute')->get('type')])) {
                foreach ($this->languages as $local => $language) {
                    $isEmpty = $this->isEmpty($attr, $language);
                    $item = ['id' => $attr->get('id'), 'isEmpty' => $isEmpty, 'isMultiLang' => true];

                    $this->fieldsAndAttrs['multiLang'][$local][] = $item;
                    $this->fieldsForTotalComplete[] = $isEmpty;
                    if ($scope == 'Global') {
                        $this->fieldsAndAttrs['attrsGlobal'][] = $item;
                    } elseif ($scope == 'Channel') {
                        $this->setItemByChannel($channels, $item);
                    }
                }
            }
        }
    }
    /**
     * @return float
     */
    protected function calculationCompleteGlobal(): float
    {
        $globalItems = array_merge($this->fieldsAndAttrs['attrsGlobal'], $this->fieldsAndAttrs['fields']);
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
        if (is_string($value) && !empty($this->entity->get($value . $language))) {
            $isEmpty = false;
        } elseif ($value instanceof Entity) {
            $type = $value->get('attribute')->get('type');
            if (in_array($type, ['array', 'arrayMultiLang', 'multiEnum', 'multiEnumMultiLang'])) {
                $attributeValue = Json::decode($value->get('value' . $language), true);
            } else {
                $attributeValue = $value->get('value' . $language);
            }
            if (!empty($attributeValue)) {
                $isEmpty= false;
            }
        }
        return $isEmpty;
    }

    /**
     * @return EntityCollection|null
     */
    protected function getAttrs(): ?EntityCollection
    {
        return $this->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->leftJoin(['productFamilyAttribute', 'attribute'])
            ->where([
                'productId' => $this->entity->get('id'),
                'productFamilyAttribute.isRequired' => true
            ])
            ->find();
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
        return !empty($channels) ? array_column($channels, 'id') : [];
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
            $this->fieldsAndAttrs['attrsChannel'][$channel][] = $item;
        }
    }
}
