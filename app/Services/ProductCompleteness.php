<?php

namespace Completeness\Services;

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
     * Update completeness for Product entity
     *
     * @return array
     */
    public function run(): array
    {
        $this->prepareRequiredFields();

        $this->prepareRequiredAttr();


        //$channelCompleteness = $this->setChannelCompleteness($entity, $requiredFields);

        $completeness['complete'] = $this->calculationLocalComplete();

        $completeness['completeGlobal'] = $this->calculationCompleteGlobal();

        //$completeness = array_merge($completeness, $this->calculationCompleteMultiLang($product));

        //$completeness['completeTotal'] = $this->calculationTotalComplete($product);

       // $isActive = $this->updateActive($entity, $completeness['completeTotal']);

        //$this->setFieldCompleteInEntity($entity, $completeness);

       // $completeness['isActive'] = $isActive;
       // $completeness['channelCompleteness'] = $channelCompleteness;

//        return $completeness;
        return [];
    }

    /**
     * @param Entity $product
     * @param array $requiredFields
     *
     * @return array
     */
    protected function setChannelCompleteness(Entity $product, array $requiredFields): array
    {
        $result = [];
        $channels = $this->getChannels($product);
        if (empty($channels) || count($channels) < 1) {
            $product->set('channelCompleteness', $result);
        } else {
            $channelCompleteness = [];
            foreach ($channels as $channel) {
                $requiredAttrChannels = $this->getRequiredAttrChannels($product, $channel->get('id'));
                $this->allFieldsComplete = array_merge($this->allFieldsComplete, $requiredAttrChannels);
                $channelRequired = array_merge(
                    $requiredFields,
                    $requiredAttrChannels
                );

                $coefficient = 100 / count($channelRequired);
                $complete = !empty($channelRequired) ? 0 : 100;

                foreach ($channelRequired as $field) {
                    if (!$this->isEmpty($field)) {
                        $complete += $coefficient;
                    }
                }
                $channelCompleteness[] = [
                    'id' => $channel->get('id'),
                    'name' => $channel->get('name'),
                    'complete' => round($complete, 2)
                ];
            }
            $result = ['total' => count($channelCompleteness), 'list' => $channelCompleteness];
            $product->set('channelCompleteness', $result);
        };
        return $result;
    }



    /**
     * Get required attributes with scope Channel
     *
     * @param Entity $product
     * @param string $channelId
     *
     * @return array
     */
    protected function getRequiredAttrChannels(Entity $product, string $channelId)
    {
        $attributes = $this
            ->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->distinct()
            ->join(['productFamilyAttribute'])
            ->where([
                'productId' => $product->get('id'),
                'productFamilyAttribute.isRequired' => true
            ])
            ->find();
        // prepare result
        $result = [];
        if (count($attributes) > 0) {
            /** @var Entity $attribute */
            foreach ($attributes as $attribute) {
                if (!in_array($attribute->get('id'), $this->getExcludedAttributes($product))) {
                    if ($attribute->get('scope') == 'Global' && !isset($result[$attribute->get('attributeId')])) {
                        $result[$attribute->get('attributeId')] = $attribute;
                    } elseif ($attribute->get('scope') == 'Channel'
                        && in_array($channelId, array_column($attribute->get('channels')->toArray(), 'id'))) {
                        $result[$attribute->get('attributeId')] = $attribute;
                    }
                }
            }
        }
        return array_values($result);
    }

    /**
     * @param Entity $product
     *
     * @return EntityCollection
     */
    protected function getChannels(Entity $product): EntityCollection
    {
        if ($product->get('type') == 'productVariant'
            && !in_array('channels', $product->get('data')->customRelations)) {
            $result = $product->get('configurableProduct')->get('channels');
        } else {
            $result = $product->get('channels');
        }
        return $result;
    }

    /**
     * @return float
     */
    protected function calculationCompleteGlobal(): float
    {
        $completeGlobal = 100;
        $globalOptions = array_merge(
            $this->fieldsAndAttrs['attrsGlobal'],
            $this->fieldsAndAttrs['fields']
        );

        if (!empty($globalOptions)) {
            $coefficientGlobalFields = 100 / count($globalOptions);
            $completeGlobal = 0;
            foreach ($globalOptions as $field) {
                if (empty($field['isEmpty'])) {
                    $completeGlobal += $coefficientGlobalFields;
                }
            }
        }
        return (float)$completeGlobal;
    }

    /**
     * @return array
     */
    protected function calculationCompleteMultiLang(): array
    {
        $completenessLang = [];
        if ($this->getConfig()->get('isMultilangActive')) {
            if ($this->entity->getEntityType() == 'Product') {
                $multiLangRequiredField = array_merge(
                    $this->getRequiredFields($this->entity->getEntityName(), true),
                    $this->getRequiredAttrGlobal($this->entity, true)
                );
            } else {
                $multiLangRequiredField =  $this->getRequiredFields($this->entity->getEntityName(), true);
            }

            $multilangCoefficient = 100 / count($multiLangRequiredField);

            foreach ($this->getLanguages() as $locale => $language) {
                $multilangComplete = 100;
                if (!empty($multiLangRequiredField)) {
                    $multilangComplete = 0;
                    foreach ($multiLangRequiredField as $field) {
                        if (!$this->isEmpty($field, $language)) {
                            $this->listFieldsComplete[] = $field . $language;
                            $multilangComplete += $multilangCoefficient;
                        }
                    }
                }
                $completenessLang['complete' . $language] = $multilangComplete;
            }
        }
        return $completenessLang;
    }

    /**
     * Prepare required attributes
     */
    protected function prepareRequiredAttr(): void
    {
        // get required attributes
        $attributes = $this
            ->getEntityManager()
            ->getRepository('ProductAttributeValue')
            ->leftJoin(['productFamilyAttribute', 'attribute'])
            ->where([
                'productId' => $this->entity->get('id'),
                'productFamilyAttribute.isRequired' => true
            ])
            ->find();

        $attributes = $this->filterAttributes($attributes);
        $multiLangFields = $this
            ->getContainer()
            ->get('metadata')->get('multilang.multilangFields', []);

        /** @var Attribute $attr */
        foreach ($attributes as $attr) {
            $scope = $attr->get('scope');

            $channels = empty($attr->get('channels')) ? [] : $attr->get('channels')->toArray();
            $channels = array_column($channels, 'id');
            $isEmpty = $this->isEmpty($attr);

            if (isset($multiLangFields[$attr->get('attribute')->get('type')])) {
                foreach ($this->languages as $local => $language) {
                    $isEmpty = $this->isEmpty($attr, $language);
                    $this->fieldsAndAttrs['multiLangAttrs' . $scope][$local][] = [
                        'id' => $attr->get('id'),
                        'isEmpty' => $isEmpty,
                        'channels' => $channels
                    ];
                }
            }
            $this->fieldsAndAttrs['attrs' . $scope][] = [
                'id' => $attr->get('id'),
                'isEmpty' => $isEmpty,
                'channels' => $channels
            ];
        }
    }

    /**
     * @param EntityCollection $attributes
     * @return array
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
     * @param $languages
     */
    public function setLanguages(array $languages): void
    {
        $this->languages = $languages;
    }

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

}
