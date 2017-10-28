<?php

namespace craft\commerce\records;

use craft\db\ActiveRecord;

/**
 * Tax category record.
 *
 * @property int    $id
 * @property string $name
 * @property string $handle
 * @property string $description
 * @property bool   $default
 *
 * @author    Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @copyright Copyright (c) 2015, Pixel & Tonic, Inc.
 * @license   https://craftcommerce.com/license Craft Commerce License Agreement
 * @see       https://craftcommerce.com
 * @package   craft.plugins.commerce.records
 * @since     1.0
 */
class TaxCategory extends ActiveRecord
{
    // Public Methods
    // =========================================================================

    /**
     * @return string
     */
    public static function tableName(): string
    {
        return '{{%commerce_taxcategories}}';
    }

    /**
     * @inheritdoc
     */
    public function rules(): array
    {
        return [
            [['handle'], 'required']
        ];
    }
}
