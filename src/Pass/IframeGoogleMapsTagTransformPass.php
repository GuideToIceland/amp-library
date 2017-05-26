<?php
/*
 * Copyright 2016 Google
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 *   http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */


namespace Lullabot\AMP\Pass;

use Lullabot\AMP\Utility\ParseUrl;
use QueryPath\DOMQuery;

use Lullabot\AMP\Utility\ActionTakenLine;
use Lullabot\AMP\Utility\ActionTakenType;

/**
 * Class IframeGoogleMapsTagTransformPass
 * @package Lullabot\AMP\Pass
 *
 * Transform all <iframe> tags which don't have noscript as an ancestor to <amp-iframe> tags
 * This class is very similar to the IframeYouTubeTagTransformationPass class.
 *
 * This class makes sure to add allow-popups to the iframe sandbox.
 * This fixes a bug in iPhone Chrome that has Google Maps installed when loading custom Google Maps.
 *
 */
class IframeGoogleMapsTagTransformPass extends BasePass
{
    /**
     * A standard iframe aspect ratio; used when we don't have enough height/width information
     * @var float
     */
    const DEFAULT_ASPECT_RATIO = 1.7778;
    const DEFAULT_WIDTH = 560;
    const DEFAULT_HEIGHT = 315;

    function pass()
    {
        $all_iframes = $this->q->find('iframe:not(noscript iframe)');
        /** @var DOMQuery $el */
        foreach ($all_iframes as $el) {
            /** @var \DOMElement $dom_el */
            $dom_el = $el->get(0);
            if (!$this->isGoogleMapsIframe($el)) {
                continue;
            }

            $lineno = $this->getLineNo($dom_el);
            $context_string = $this->getContextString($dom_el);
            list($src, $converted_to_https) = $this->getIframeSrc($el);

            // Don't try to convert if a proper url was not found
            if ($src === false) {
                continue;
            }

            if ($el->hasAttr('class')) {
                $class_attr = $el->attr('class');
            }

            // Make sure to add allow-popups to the sandbox to prevent iPhone Chrome from opening the Google Maps application
            /** @var \DOMElement $new_dom_el */
            $el->after("<amp-iframe sandbox=\"allow-scripts allow-popups allow-same-origin\" layout=\"responsive\"></amp-iframe>");
            $new_el = $el->next();
            $new_dom_el = $new_el->get(0);
            $this->setStandardAttributesFrom($el, $new_el, self::DEFAULT_WIDTH, self::DEFAULT_HEIGHT, self::DEFAULT_ASPECT_RATIO);
            $new_el->attr('src', $src);
            if (!empty($class_attr)) {
                $new_el->attr('class', $class_attr);
            }

            // Remove the iframe and its children
            $el->removeChildren()->remove();
            if ($converted_to_https) {
                $this->addActionTaken(new ActionTakenLine('iframe', ActionTakenType::IFRAME_CONVERTED_AND_HTTPS, $lineno, $context_string));
            } else {
                $this->addActionTaken(new ActionTakenLine('iframe', ActionTakenType::IFRAME_CONVERTED, $lineno, $context_string));
            }
            $this->context->addLineAssociation($new_dom_el, $lineno);
        }

        return $this->transformations;
    }

    /**
     * @param DOMQuery $el
     * @return bool
     */
    protected function isGoogleMapsIframe(DOMQuery $el)
    {
        $href = $el->attr('src');
        if (empty($href)) {
            return false;
        }

        if (preg_match('&(*UTF8)(google\.com/maps/(d/)?embed|maps.google.com)&i', $href)) {
            return true;
        }

        return false;
    }

    /**
     * @param DOMQuery $el
     * @return array
     */
    protected function getIframeSrc(DOMQuery $el)
    {
        $src = trim($el->attr('src'));
        if (empty($src)) {
            return [false, false];
        }

        $parsed_url = ParseUrl::parse_url($src);
        if ($parsed_url === false) {
            return [false, false];
        }

        // Deal with relative URLs e.g "//example.com/iframe.html"
        if (empty($parsed_url['scheme']) && strpos($src, '//') === 0) {
            return ['https:' . $src, true];
        } else if (!empty($parsed_url['scheme']) && $parsed_url['scheme'] == 'http') {
            return [preg_replace('/(*UTF8)^http:/i', 'https:', $src), true];
        } else {
            return [$src, false];
        }
    }
}
