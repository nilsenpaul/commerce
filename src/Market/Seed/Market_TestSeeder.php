<?php

namespace Market\Seed;
use Craft\DateTime;
use Craft\Market_ProductModel;
use Craft\Market_ProductTypeModel;
use Craft\FieldLayoutModel;
use Craft\Market_OptionTypeModel;
use Craft\Market_OptionValueModel;
use Craft\Market_TaxCategoryModel;
use Craft\Market_TaxRateModel;
use Craft\Market_TaxZoneModel;
use Craft\Market_VariantModel;
use Market\Product\Creator;

/**
 * Test Data useful during development
 */
class Market_TestSeeder implements Market_SeederInterface
{
    public function seed()
    {
        $this->productTypes();
        $this->optionTypes();
        $this->taxCategories();
        $this->products();
        $this->taxZones();
        $this->taxRates();
    }

    /**
     * @throws \Craft\Exception
     * @throws \Exception
     */
    private function optionTypes()
    {
        //color
        $colorOptionType = new Market_OptionTypeModel;
        $colorOptionType->name = 'Color';
        $colorOptionType->handle = 'color';
        \Craft\craft()->market_optionType->save($colorOptionType);

        $colorOptionValues = [new Market_OptionValueModel, new Market_OptionValueModel];
        $colorOptionValues[0]->name = 'blue';
        $colorOptionValues[0]->displayName = 'blue';
        $colorOptionValues[0]->position = 1;
        $colorOptionValues[1]->name = 'red';
        $colorOptionValues[1]->displayName = 'red';
        $colorOptionValues[1]->position = 2;

        \Craft\craft()->market_optionValue->saveOptionValuesForOptionType($colorOptionType, $colorOptionValues);

        //size
        $sizeOptionType = new Market_OptionTypeModel;
        $sizeOptionType->name = 'Size';
        $sizeOptionType->handle = 'size';

        \Craft\craft()->market_optionType->save($sizeOptionType);

        $sizeOptionValues = [new Market_OptionValueModel, new Market_OptionValueModel];
        $sizeOptionValues[0]->name = 'xl';
        $sizeOptionValues[0]->displayName = 'xl';
        $sizeOptionValues[0]->position = 1;
        $sizeOptionValues[1]->name = 'm';
        $sizeOptionValues[1]->displayName = 'm';
        $sizeOptionValues[1]->position = 2;

        \Craft\craft()->market_optionValue->saveOptionValuesForOptionType($sizeOptionType, $sizeOptionValues);
    }

    /**
     * @throws \Craft\Exception
     * @throws \Exception
     */
    private function productTypes()
    {
        $productType = new Market_ProductTypeModel;
        $productType->name = 'Default Product';
        $productType->handle = 'defaultProduct';

        $fieldLayout = FieldLayoutModel::populateModel(['type' => 'Market_Product']);
        $productType->setFieldLayout($fieldLayout);
        \Craft\craft()->market_productType->save($productType);
    }

    /**
     * @throws \Craft\Exception
     */
    private function taxCategories()
    {
        $taxCategories = Market_TaxCategoryModel::populateModels([[
            'name' => 'General',
            'default' => 1,
        ], [
            'name' => 'Food',
            'default' => 0,
        ], [
            'name' => 'Clothes',
            'default' => 0,
        ]]);

        foreach($taxCategories as $category) {
            \Craft\craft()->market_taxCategory->save($category);
        }
    }

    /**
     * @throws \Craft\Exception
     * @throws \Exception
     */
    private function taxZones()
    {
        //europe
        $germany = \Craft\craft()->market_country->getByAttributes(['name' => 'Germany']);
        $italy = \Craft\craft()->market_country->getByAttributes(['name' => 'Italy']);
        $france = \Craft\craft()->market_country->getByAttributes(['name' => 'France']);
        $euCountries = [$germany->id, $italy->id, $france->id];

        $euZone = Market_TaxZoneModel::populateModel([
            'name'         => 'Europe',
            'countryBased' => true,
        ]);

        \Craft\craft()->market_taxZone->save($euZone, $euCountries, []);

        //usa states
        $florida = \Craft\craft()->market_state->getByAttributes(['name' => 'Florida']);
        $alaska = \Craft\craft()->market_state->getByAttributes(['name' => 'Alaska']);
        $texas = \Craft\craft()->market_state->getByAttributes(['name' => 'Texas']);
        $usaStates = [$florida->id, $alaska->id, $texas->id];

        $usaZone = Market_TaxZoneModel::populateModel([
            'name'         => 'USA',
            'countryBased' => false,
        ]);

        \Craft\craft()->market_taxZone->save($usaZone, [], $usaStates);
    }

    /**
     * @throws \Craft\Exception
     */
    private function taxRates()
    {
        $zones = \Craft\craft()->market_taxZone->getAll(false);
        $categories = \Craft\craft()->market_taxCategory->getAll();

        foreach($zones as $zone) {
            foreach($categories as $category) {
                $rate = Market_TaxRateModel::populateModel([
                    'name' => $category->name . '-' . $zone->name,
                    'rate' => mt_rand(1, 10000) / 100000,
                    'include' => mt_rand(1, 2) - 1,
                    'taxCategoryId' => $category->id,
                    'taxZoneId' => $zone->id,
                ]);

                \Craft\craft()->market_taxRate->save($rate);
            }
        }
    }

    /**
     * @throws \Craft\HttpException
     */
    private function products()
    {
        $productTypes = \Craft\craft()->market_productType->getAll();

        /** @var Market_ProductModel $product */
        $product = Market_ProductModel::populateModel([
            'typeId' => $productTypes[0]->id,
            'enabled' => 1,
            'authorId' => \Craft\craft()->userSession->id,
            'availableOn' => new DateTime(),
            'expiresOn' => null,
            'taxCategoryId' => \Craft\craft()->market_taxCategory->getDefaultId(),
        ]);

        $product->getContent()->title = 'Test Product';

        $productCreator = new Creator();
        $productCreator->save($product);

        //master variant
        $masterVariant = Market_VariantModel::populateModel([
            'productId'         => $product->id,
            'isMaster'          => 1,
            'sku'               => 'testSku',
            'price'             => 111,
            'unlimitedStock'    => 1,
        ]);
        \Craft\craft()->market_variant->save($masterVariant);

        //option types
        $optionTypes = \Craft\craft()->market_optionType->getAll();
        $ids = array_map(function($type) {
            return $type->id;
        }, $optionTypes);
        \Craft\craft()->market_product->setOptionTypes($product->id, $ids);
    }
}