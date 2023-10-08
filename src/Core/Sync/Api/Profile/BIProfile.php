<?php declare(strict_types=1);
/**
 * Copyright (c) 2023, elio GmbH.
 * All rights reserved.
 *
 * Redistribution and use in source and binary forms, with or without
 * modification, are permitted provided that the following conditions are met:
 *
 * 1. Redistributions of source code must retain the above copyright notice,
 * this list of conditions and the following disclaimer.
 *
 * 2. Redistributions in binary form must reproduce the above copyright notice,
 * this list of conditions and the following disclaimer in the documentation
 * and/or other materials provided with the distribution.
 *
 * 3. Neither the name of the copyright holder nor the names of its contributors
 * may be used to endorse or promote products derived from this software without
 * specific prior written permission.
 *
 * THIS SOFTWARE IS PROVIDED BY THE COPYRIGHT HOLDERS AND CONTRIBUTORS "AS IS"
 * AND ANY EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE
 * IMPLIED WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE
 * ARE DISCLAIMED. IN NO EVENT SHALL THE COPYRIGHT HOLDER OR CONTRIBUTORS BE
 * LIABLE FOR ANY DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR
 * CONSEQUENTIAL DAMAGES (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF
 * SUBSTITUTE GOODS OR SERVICES; LOSS OF USE, DATA, OR PROFITS; OR BUSINESS
 * INTERRUPTION) HOWEVER CAUSED AND ON ANY THEORY OF LIABILITY, WHETHER IN
 * CONTRACT, STRICT LIABILITY, OR TORT (INCLUDING NEGLIGENCE OR OTHERWISE)
 * ARISING IN ANY WAY OUT OF THE USE OF THIS SOFTWARE, EVEN IF ADVISED OF THE
 * POSSIBILITY OF SUCH DAMAGE.
 */

namespace Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Api\Profile;

use Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Api\Output\BIOutput;
use Elio\ElioSearch\Core\Sync\DataTypes\ContentType;
use Elio\ElioSearch\Core\Sync\DataTypes\ProductType;
use Elio\ElioSearch\Core\Sync\Defaults\SyncDefaults;
use Elio\ElioSearch\Core\Sync\Export\Converter\ContentConverter;
use Elio\ElioSearch\Core\Sync\Export\Converter\ProductConverter;
use Elio\ElioSearch\Core\Sync\Profile\SyncProfileInterface;

/**
 * Class ProductProfile
 * @package Elio\ElioBatteryIncludedSearchExtension\Core\Sync\Api\Profile
 * @category Shopware
 * @author elio GmbH <support@elio-systems.com>
 * @author Danil Lukov <dl@elio-systems.com>
 * @copyright Copyright (c) 2023, elio GmbH (https://www.elio-systems.com)
 */
class BIProfile implements SyncProfileInterface
{
    public function getType(): string
    {
        return SyncDefaults::PROFILE_SYNC;
    }

    public function getName(): string
    {
        return 'BI Sync';
    }

    /**
     * @return string[]
     */
    public function getDataTypes(): array
    {
        return [ProductType::class, ContentType::class];
    }

    public function getConverters(): array
    {
        return [
            ProductType::class => ProductConverter::class,
            ContentType::class => ContentConverter::class,
        ];
    }

    public function getOutputs(): array
    {
        return [BIOutput::TYPE, 'JSON'];
    }

    public function getFeatures(): array
    {
        return SyncProfileInterface::FEATURES;
    }
}