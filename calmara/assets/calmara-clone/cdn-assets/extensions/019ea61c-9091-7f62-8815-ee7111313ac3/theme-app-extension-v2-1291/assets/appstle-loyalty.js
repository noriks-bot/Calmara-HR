(function LoyaltyAppCore() {
  const LIBS = {
    jQuery: 'https://ajax.googleapis.com/ajax/libs/jquery/3.6.0/jquery.min.js',
  };

  const loadScript = (name, url) => {
    if (window?.[name] || document.querySelector(`script[data-lib="${name}"]`)) return;
    const s = document.createElement('script');
    s.src = url;
    s.async = true;
    s.setAttribute('data-lib', name);
    s.onload = () => console.log(`[LoyaltyAppCore] ${name} loaded`);
    s.onerror = () => console.warn(`[LoyaltyAppCore] ${name} failed to load`);
    document.head.appendChild(s);
  };

  const executeLoyaltyFeatures = () => {
    Object.entries(LIBS)?.forEach(([name, url]) => loadScript(name, url));
    const checkReady = async () => {
      if (Object.keys(LIBS)?.every(name => window?.[name])) {
        console.log('[LoyaltyAppCore] All libraries loaded');
        if (window?.['_ALConfig'] && window?.['_ALConfig'].widget_setting?.showWidget) {
          await executeLoyaltyBlocks();
          observeChanges();
          executeLoyaltyRedeemWidgetFeatures();
        }
      } else {
        setTimeout(checkReady, 1000);
      }
    };
    checkReady().then(() => {});
  };

  const decodeHtml = (html = '') => {
    try {
      const txt = document.createElement('textarea');
      txt.innerHTML = html;
      return txt?.value;
    } catch {
      return html;
    }
  };
  const processMoneyFormat = () => {
    try {
      const decoded = decodeHtml(_ALConfig?.shopMoneyFormat || '');
      const tokens = [
        'amount',
        'amount_no_decimals',
        'amount_with_comma_separator',
        'amount_no_decimals_with_comma_separator',
        'amount_no_decimals_with_space_separator',
        'amount_with_apostrophe_separator',
      ];
      const tokenPattern = new RegExp(`\\{\\{\\s*(${tokens?.join('|')})\\s*\\}\\}`, 'gi');
      const placeholder = '[[AMOUNT]]';
      const stripped = decoded?.replace(tokenPattern, placeholder);
      if (!stripped?.includes(placeholder)) {
        return '';
      }
      const [prefix = '', suffix = ''] = stripped?.split(placeholder);
      const clean = (prefix + suffix)?.replace(/<[^>]*>/g, '')?.trim();
      return clean || '';
    } catch {
      return '';
    }
  };
  const currencySymbol = processMoneyFormat();
  const isCustomerEnrolled =
    _ALConfig?.allowCustomerOptIn === true
      ? _ALConfig?.customerId && _ALConfig?.customerLoyalty
        ? Object.keys(_ALConfig?.customerLoyalty || {})?.length > 0
        : false
      : true;

  const hasCommonItemsBetweenTwoListItems = (arr1 = [], arr2 = []) =>
    arr1?.some(item1 => arr2?.some(item2 => item1?.trim()?.toLowerCase() === item2?.trim()?.toLowerCase()));

  const isRuleAllowedByCustomerTags = (item, customerTags) => {
    if (!item?.allowedCustomerTags || !customerTags) return true;
    const allowedCustomersOnly = item?.allowedCustomerTags.split(',');
    return hasCommonItemsBetweenTwoListItems(customerTags, allowedCustomersOnly);
  };

  const isRuleRestrictedByCustomerTags = (item, customerTags) => {
    if (!item?.restrictCustomerTags) return false;
    const restrictCustomerTags = item?.restrictCustomerTags?.split(',');
    return hasCommonItemsBetweenTwoListItems(customerTags, restrictCustomerTags);
  };

  const isRuleRestrictedByProductId = item => {
    if (!item?.excludeProducts) return false;
    const restrictedProductsIdOnly = item?.excludeProducts?.split(',');
    const productId = window?._ALConfig?.product?.id;
    return restrictedProductsIdOnly.some(restrictedId => restrictedId?.toString() === productId?.toString());
  };
  const isRuleRestrictedByVariantId = item => {
    if (!item?.excludeVariantIds) return false;
    const restrictedVariantIdsOnly = item?.excludeVariantIds?.split(',');
    const url = getCurrentPageURL();
    const variantId = Number(url?.searchParams?.get('variant') || getCurrentProductFormVariantId()) || 0;
    return restrictedVariantIdsOnly.some(restrictedId => restrictedId?.toString() === variantId?.toString());
  };

  const isRuleAllowedByProductTags = item => {
    const productTags = window?._ALConfig?.product?.tags;
    if (!item?.allowedProductTags || !productTags) return true;
    const allowedProductTagsOnly = item?.allowedProductTags?.split(',').map(tag => tag.trim());;
    return productTags.some(tag => allowedProductTagsOnly?.includes(tag));
  };

  const isRuleRestrictedByProductTags = item => {
    const productTags = window?._ALConfig?.product?.tags;
    if (!item?.restrictProductTags || !productTags) return false;
    const restrictProductTagsOnly = item?.restrictProductTags?.split(',');
    return productTags.some(tag => restrictProductTagsOnly?.includes(tag));
  };

  const isRuleRestricted = item => {
    return (
      isRuleRestrictedByProductId(item) ||
      isRuleRestrictedByVariantId(item) ||
      isRuleRestrictedByCustomerTags(item, _ALConfig?.customerTags) ||
      isRuleRestrictedByProductTags(item)
    );
  };

  const getMaxPointsFromApplicableRules = (ruleType, points) => {
    return Math.max(ruleType || 0, points);
  };
  const matchProductVariantData = (productVariantsData, productId, variantId) => {
    if (productVariantsData && productVariantsData?.length > 0) {
      return productVariantsData?.some(productVariant => {
        if (productVariant?.variant === true) {
          return Number(productVariant?.id) === Number(variantId);
        } else {
          return Number(productVariant?.id) === Number(productId);
        }
      });
    }
    return false;
  };
  const isDisableOnSaleProductVariant = (rule, variant) => {
    return (
      rule?.disableForOnSellProduct === true &&
      variant?.compare_at_price !== null &&
      Number(variant?.compare_at_price) > Number(variant?.price)
    );
  };
  const getCurrentPageURL = () => {
    const currentPageUrl = document.URL;
    return new URL(currentPageUrl);
  };

  const ATC_SELECTORS = [
    'button[type="submit"][name="add"]',
    'input[type="submit"][name="add"]',
    'button[type="submit"][data-add-to-cart]',
    'button[type="submit"][data-action="add-to-cart"]',
    'button[type="submit"][aria-label*="add to cart" i]',
    '[data-add-to-cart]',
    '[data-action="add-to-cart"]',
    'button.add-to-cart',
    '#AddToCart',
    'button[name="add-to-cart"]',
  ];

  const hasVisibleAtc = form => {
    return ATC_SELECTORS.some(selector => {
      const button = form?.querySelector(selector);
      return button && button?.offsetParent !== null;
    });
  };

  const getProductIdFromForm = form => {
    return (
      form?.dataset?.productId ||
      form?.dataset?.productid ||
      form?.getAttribute?.('data-product-id') ||
      form?.getAttribute?.('data-productid') ||
      form?.querySelector('input[name="product-id"], input[name="product[id]"]')?.value ||
      form?.querySelector('input[name="id"], select[name="id"]')?.dataset?.productid ||
      form?.querySelector('input[name="id"], select[name="id"]')?.getAttribute?.('data-productid') ||
      null
    );
  };

  const getFormSignature = form => `${form?.id || ''} ${form?.className || ''}`.toLowerCase();

  const isLikelyMainProductForm = form => {
    const sig = getFormSignature(form);
    return (
      sig.includes('product-form-template') ||
      sig.includes('product-form') ||
      sig.includes('product_form') ||
      sig.includes('buy-buttons__form') ||
      sig.includes('main-product') ||
      sig.includes('product-page')
    );
  };

  const isLikelyQuickAddForm = form => {
    const sig = getFormSignature(form);
    return (
      sig.includes('quick-add') ||
      sig.includes('quick_add') ||
      sig.includes('quickshop') ||
      sig.includes('quick-shop') ||
      sig.includes('drawer') ||
      sig.includes('mini-cart') ||
      sig.includes('ajax-cart') ||
      sig.includes('cart-drawer') ||
      sig.includes('cart_drawer')
    );
  };

  const getEligibleProductForm = productId => {
    const forms = Array.from(document.querySelectorAll('form[action*="/cart/add"]'));
    if (!forms?.length) return null;

    const matchingForms = forms.filter(form => String(getProductIdFromForm(form)) === String(productId));
    const scopedForms = matchingForms.length ? matchingForms : forms;
    if (!scopedForms.length) return null;

    const originalChoice = scopedForms.find(form => hasVisibleAtc(form)) || scopedForms?.[scopedForms?.length - 1] || null;

    const visibleForms = scopedForms.filter(hasVisibleAtc);
    const candidates = visibleForms.length ? visibleForms : scopedForms;

    const visibleMain = candidates.find(form => hasVisibleAtc(form) && isLikelyMainProductForm(form) && !isLikelyQuickAddForm(form));
    if (visibleMain) return visibleMain;

    const visibleAnyMain = candidates.find(form => hasVisibleAtc(form) && isLikelyMainProductForm(form));
    if (visibleAnyMain) return visibleAnyMain;

    const anyMain = candidates.find(isLikelyMainProductForm);
    if (anyMain) return anyMain;

    const visibleNonQuick = candidates.find(form => hasVisibleAtc(form) && !isLikelyQuickAddForm(form));
    if (visibleNonQuick) return visibleNonQuick;

    const nonQuickCandidates = candidates.filter(form => !isLikelyQuickAddForm(form));
    if (nonQuickCandidates.length) {
      return nonQuickCandidates[nonQuickCandidates.length - 1];
    }

    return originalChoice;
  };

  const getCurrentProductFormVariantId = () => {
    const productId = window?._ALConfig?.product?.id;
    const targetForm = getEligibleProductForm(productId);
    return targetForm?.querySelector('input[name="id"], select[name="id"]')?.value || null;
  };

  const getCurrentProductFormSellingPlanId = () => {
    const productId = window?._ALConfig?.product?.id;
    const targetForm = getEligibleProductForm(productId);
    return targetForm?.querySelector('[data-selected-selling-plan]')?.getAttribute('data-selected-selling-plan') || null;
  };

  const getMatchedSellingPlan = variant => {
    const url = getCurrentPageURL();
    const queryVariantId = Number(url?.searchParams?.get('variant') || getCurrentProductFormVariantId()) || 0;
    const querySellingPlanId = Number(url?.searchParams?.get('selling_plan') || getCurrentProductFormSellingPlanId()) || 0;

    const isMatchedVariantId = Number(variant?.id) === Number(queryVariantId);
    if (isMatchedVariantId) {
      return (
        variant?.selling_plan_allocations?.length > 0 &&
        variant?.selling_plan_allocations?.find(plan => Number(plan?.selling_plan_id) === Number(querySellingPlanId))
      );
    }
    return {};
  };
  const calculateSellingPlanPoints = (sellingPlanAssociatedVariant, currentRule, shopifyCurrencies) => {
    let variantPrice = sellingPlanAssociatedVariant?.price / 100 || 0;
    if (
      shopifyCurrencies &&
      _ALConfig?.currency &&
      window?.Shopify?.currency?.active &&
      _ALConfig?.currency !== window?.Shopify?.currency?.active
    ) {
      const convertedPrice = shopifyCurrencies.convert(
        sellingPlanAssociatedVariant?.price / 100 || 0,
        window?.Shopify?.currency?.active,
        _ALConfig?.currency
      );
      variantPrice = convertedPrice.toFixed();
    } else {
      variantPrice = sellingPlanAssociatedVariant?.price / 100 || 0;
    }
    return Number(variantPrice) * Number(currentRule?.basePoints) || 0;
  };
  const firstAvailableVariant = product => {
    if (product?.variants && product?.variants?.length > 0) {
      for (const variant of product?.variants) {
        if (variant?.available === true) {
          return variant;
        } else {
          console.log('Variant not in stock: ' + variant?.id);
        }
      }
    }
  };
  const detectVariant = () => {
    let variant;
    const product = window?._ALConfig?.product || null;
    const url = getCurrentPageURL();
    const isVariantUrl = url?.searchParams.get('variant');
    const formVariantId = getCurrentProductFormVariantId();
    const currentVariantId = Number(isVariantUrl || formVariantId) || 0;

    if (currentVariantId) {
      variant = product?.variants && product?.variants?.find(x => Number(x?.id) === currentVariantId);
    }

    if (!variant) {
      variant = firstAvailableVariant(product);
    }
    return variant;
  };

  const getStandAloneElements = () => {
    if (typeof jQuery === 'undefined') return null;
    const standalone = jQuery('.appstle_loyalty_stand_alone_selector:not(.processed)');
    if (standalone?.length) return standalone;
    const collection = jQuery('.appstle_loyalty_stand_alone_collection_selector:not(.processed)');
    if (collection?.length) return collection;
    return null;
  };

  const getProductDetailsByHandle = async handle => {
    return await fetch(`https://${window?._ALConfig?.public_domain}/products/${handle}.js/`).then(response => response.json());
  };

  const retrieveCurrencies = async () => {
    try {
      const response = await fetch('https://cdn-assets/s/javascripts/currencies.js');
      if (!response.ok) {
        console.error('Failed to retrieve currencies.js:', response.status, response.statusText);
        return null;
      }

      const currenciesJs = await response.text();
      const currencyDefinition = currenciesJs.match(/var Currency=(.*?);(\s*\/\/# sourceMappingURL=.*?)?$/s);

      if (currencyDefinition && currencyDefinition?.[1]) {
        try {
          const modifiedCurrenciesJs = `(() => { return { Currency: ${currencyDefinition[1]} }; })()`;
          const currencyModule = new Function('return ' + modifiedCurrenciesJs)();
          return currencyModule?.Currency;
        } catch (error) {
          console.error('Error creating Currencies object:', error.message);
          return null;
        }
      } else {
        console.error('Failed to extract Currency definition from currencies.js');
        return null;
      }
    } catch (error) {
      console.error('Error fetching or processing currencies.js:', error.message);
      return null;
    }
  };

  const getDefaultShopCurrency = () => (_ALConfig?.currency === _ALConfig?.storeCurrency ? _ALConfig?.currency : _ALConfig?.storeCurrency);

  const getTotalAcquirePointsFromActivityRules = (rules, variantPrice, getVariant, product, shopifyCurrencies) => {
    const pointsByType =
      rules &&
      rules?.reduce(
        (accumulator, currentRule) => {
          const ruleType = currentRule?.type?.toUpperCase();
          const rewardAssignType = currentRule?.rewardAssignType || 'DYNAMIC';
          const isFixedRewardType = rewardAssignType === 'FIXED';
          const productData = currentRule?.productData ? JSON.parse(currentRule?.productData) : [];

          if (ruleType === 'PURCHASE') {
            if (isDisableOnSaleProductVariant(currentRule, getVariant)) {
              accumulator.bestFixedByType[ruleType] = 0;
              accumulator.bestDynamicByType[ruleType] = 0;
              accumulator.points[ruleType] = 0;
              return accumulator;
            }

            const currentSellingPlan = getMatchedSellingPlan(getVariant);
            let points = 0;

            if (currentSellingPlan && Object.keys(currentSellingPlan)?.length > 0) {
              // selling plan calculation
              points = calculateSellingPlanPoints(currentSellingPlan, currentRule, shopifyCurrencies);
            } else {
              // normal purchase calculation
              points = currentRule?.basePoints * variantPrice;
            }

            // fixed means use basePoints, not price-based
            if (isFixedRewardType) {
              points = currentRule?.basePoints;
            }

            // Enforce minimum order value
            if (currentRule?.minimumOrderValue > 0 && variantPrice < currentRule?.minimumOrderValue) {
              points = 0;
            }
            // Cap at maximum points
            if (currentRule?.maximumPoints && points > currentRule?.maximumPoints) {
              points = currentRule?.maximumPoints;
            }

            // update best fixed / dynamic for this ruleType
            const bucketKey = isFixedRewardType ? 'bestFixedByType' : 'bestDynamicByType';
            const previousBest = accumulator[bucketKey][ruleType] || 0;
            accumulator[bucketKey][ruleType] = Math.max(previousBest, points || 0);

            // final PURCHASE points = bestFixed + bestDynamic
            const bestFixed = accumulator.bestFixedByType[ruleType] || 0;
            const bestDynamic = accumulator.bestDynamicByType[ruleType] || 0;
            accumulator.points[ruleType] = bestFixed + bestDynamic;

            return accumulator;
          }
          if (ruleType === 'BUY_PRODUCT') {
            const points = currentRule?.basePoints;
            const isProductVariantMatched = matchProductVariantData(productData, product?.id, getVariant?.id);
            if (isProductVariantMatched) {
              accumulator.points[ruleType] = getMaxPointsFromApplicableRules(accumulator?.points[ruleType], points);
            } else {
              accumulator.points[ruleType] =
                accumulator.points[ruleType] && accumulator.points[ruleType] > 0 ? accumulator.points[ruleType] : 0;
            }
          }
          if (ruleType === 'SUBSCRIPTION') {
            if (isDisableOnSaleProductVariant(currentRule, getVariant)) {
              accumulator.points[ruleType] = 0;
            } else {
              const currentSellingPlan = getMatchedSellingPlan(getVariant);
              if (currentSellingPlan !== undefined && currentSellingPlan && Object.keys(currentSellingPlan)?.length > 0) {
                let points = calculateSellingPlanPoints(currentSellingPlan, currentRule, shopifyCurrencies);
                // Enforce minimum order value
                if (currentRule?.minimumOrderValue > 0 && variantPrice < currentRule?.minimumOrderValue) {
                  points = 0;
                }
                // Cap at maximum points
                if (currentRule?.maximumPoints && points > currentRule?.maximumPoints) {
                  points = currentRule?.maximumPoints;
                }
                accumulator.points[ruleType] = getMaxPointsFromApplicableRules(accumulator?.points[ruleType], points);
              } else {
                accumulator.points[ruleType] = 0;
              }
            }
          }
          return accumulator;
        },
        { points: {}, bestFixedByType: {}, bestDynamicByType: {} }
      );
    return (
      (pointsByType?.points &&
        Object.keys(pointsByType?.points)?.length > 0 &&
        Object.values(pointsByType?.points)?.reduce((accumulator, currentValue) => accumulator + currentValue, 0)) ||
      0
    );
  };

  const calculateAcquirePoints = async (config, product, variantId) => {
    if (product) {
      const shopifyCurrencies = await retrieveCurrencies();
      const getVariant = product && product?.variants && product?.variants?.find(variant => variant?.id === variantId);
      const variantPriceAmount = getVariant?.price / 100 || 0;
      let variantPrice = variantPriceAmount;

      if (
        shopifyCurrencies &&
        _ALConfig?.currency &&
        window?.Shopify?.currency?.active &&
        _ALConfig?.currency !== window?.Shopify?.currency?.active
      ) {
        const convertedPrice = shopifyCurrencies.convert(variantPriceAmount, window?.Shopify?.currency?.active, getDefaultShopCurrency());
        variantPrice = convertedPrice.toFixed();
      } else {
        variantPrice = variantPriceAmount;
      }

      if (config?.pointEarnRules && config?.pointEarnRules?.length && variantPrice) {
        const storeCreditRules =
          config?.pointEarnRules?.filter(
            rule =>
              rule?.status?.toUpperCase() === 'ACTIVE' &&
              rule?.earnType === 'STORE_CREDIT' &&
              isRuleAllowedByCustomerTags(rule, config?.customerTags) &&
              isRuleAllowedByProductTags(rule) &&
              !isRuleRestricted(rule) &&
              !rule?.allowedOrderTags &&
              !rule?.excludeOrderTags
          ) || [];

        const earnPointRules =
          config?.pointEarnRules?.filter(
            rule =>
              rule?.status?.toUpperCase() === 'ACTIVE' &&
              rule?.earnType !== 'STORE_CREDIT' &&
              isRuleAllowedByCustomerTags(rule, config?.customerTags) &&
              isRuleAllowedByProductTags(rule) &&
              !isRuleRestricted(rule) &&
              !rule?.allowedOrderTags &&
              !rule?.excludeOrderTags
          ) || [];

        const storeCreditPoints = getTotalAcquirePointsFromActivityRules(
          storeCreditRules,
          variantPrice,
          getVariant,
          product,
          shopifyCurrencies
        );
        const earnPoints = getTotalAcquirePointsFromActivityRules(earnPointRules, variantPrice, getVariant, product, shopifyCurrencies);

        return {
          earnPoints: roundAndFormatNumber(earnPoints),
          storeCreditPoints: roundAndFormatNumber(storeCreditPoints),
        };
      }
    }
  };

  async function getAcquirePointsByProduct(config, variantId) {
    const product = config?.product || [];
    return await calculateAcquirePoints(config, product, variantId);
  }

  async function getAcquirePointsByFeaturedProductsOrCollections(config, product, variantId) {
    return await calculateAcquirePoints(config, product, variantId);
  }

  const formatNumberByLoyalty = num => {
    if (typeof num === 'number') {
      if (Number.isInteger(num) || num % 1 === 0) {
        return num.toFixed(0);
      } else {
        return num.toFixed(2);
      }
    }
    return 0;
  };

  const roundAndFormatNumber = num => {
    switch (_ALConfig?.pointRoundType) {
      case 'ROUND_UP':
        return formatNumberByLoyalty(Math.ceil(num));
      case 'ROUND_DOWN':
        return formatNumberByLoyalty(Math.floor(num));
      case 'NO_ROUND':
        return formatNumberByLoyalty(num);
      default:
        return formatNumberByLoyalty(num);
    }
  };

  const getSelectedProductQuantity = () => {
    if (_ALConfig?.widget_setting?.enableAcquirePointsQuantitySelector === true) {
      const productId = window?._ALConfig?.product?.id;
      const targetForm = getEligibleProductForm(productId);
      const quantityInput =
        targetForm?.querySelector('input[name="quantity"], .quantity__input, [aria-label="Quantity"]') ||
        document.querySelector('input[name="quantity"], .quantity__input, [aria-label="Quantity"]');
      if (quantityInput) return parseInt(quantityInput?.['value'], 10) || 1;
    }
    return 1;
  };

  const showAcquirePointsBadge = (totalPoints, badgeSettings, standElement, widgetLabels) => {
    const { earnPoints, storeCreditPoints } = totalPoints;
    const quantity = getSelectedProductQuantity() || 1;
    const acquirePoints = formatNumberByLoyalty(Number(earnPoints) * quantity);
    const acquireStoreCredits = formatNumberByLoyalty(Number(storeCreditPoints) * quantity);

    const acquirePointsBackgroundColor = badgeSettings?.acquirePointsBackgroundColor || '#3b82f6';
    const acquirePointsTextColor = badgeSettings?.acquirePointsTextColor || '#fff';

    const loggedOutAcquirePointsLabel =
      !window?._ALConfig?.customerId && badgeSettings?.loggedOutFlagAcquirePoints === true && widgetLabels?.acquirePointsLoggedOutText
        ? widgetLabels?.acquirePointsLoggedOutText
            ?.replaceAll('{{totalPoints}}', acquirePoints || '0')
            ?.replaceAll('{{totalStoreCreditPoints}}', acquireStoreCredits || '0')
        : '';
    const acquirePointsLabel = (widgetLabels?.acquirePointsLabel || 'Acquire Points: {{totalPoints}}')?.replaceAll(
      '{{totalPoints}}',
      acquirePoints || '0'
    );
    const acquireStoreCreditLabel = (widgetLabels?.acquireStoreCreditLabel || 'Acquire Store Credits: {{totalStoreCreditPoints}}')?.replaceAll(
      '{{totalStoreCreditPoints}}',
      acquireStoreCredits || '0'
    );

    const content =
      (acquirePoints && Number(acquirePoints) > 0) || (acquireStoreCredits && Number(acquireStoreCredits) > 0)
        ? `<div class="loyalty-acquire-points-badge acquire-points-badge" style="background-color: ${acquirePointsBackgroundColor}; color: ${acquirePointsTextColor};">
              ${
                loggedOutAcquirePointsLabel
                  ? `<span class="loyalty-acquire-points-logged-out-label">${loggedOutAcquirePointsLabel}</span>`
                  : `
                  ${acquirePoints > 0 ? `<span class="loyalty-acquire-earn-points-label">${acquirePointsLabel}</span>` : ''}
                  ${acquireStoreCredits > 0 ? `<span class="loyalty-acquire-store-credit-label">${acquireStoreCreditLabel}</span>` : ''}
              `
              }
        </div>`
        : '';
    let newContent = jQuery(content);
    const selector = '.appstle-loyalty-acquire-points-block';

    if (standElement) {
      jQuery(standElement).addClass('processed');
      const existingBadge = jQuery(standElement).find('.loyalty-acquire-points-badge');
      if (existingBadge?.length) {
        existingBadge.replaceWith(newContent);
      } else {
        jQuery(standElement).append(newContent);
      }
    } else {
      const existingBadge = jQuery(selector).siblings('.loyalty-acquire-points-badge');
      if (existingBadge?.length) {
        existingBadge.replaceWith(newContent);
      } else {
        newContent.insertAfter(selector);
      }
    }
  };

  const showPointBalance = (points, storeCreditBalance, widgetSettings, widgetLabels) => {
    const showPointsBalanceBackgroundColor = widgetSettings?.pointsBalanceBackgroundColor || 'inherit';
    const showPointsBalanceTextColor = widgetSettings?.pointsBalanceTextColor || '#000000';

    const content = `<div class="loyalty-point-balance" style="background-color: ${showPointsBalanceBackgroundColor}; color: ${showPointsBalanceTextColor};">${
      widgetLabels?.pointsBalanceLabel
        ?.replace(/{{points}}/g, points || '0')
        ?.replace(/{{store_credits}}/g, `${currencySymbol}${storeCreditBalance ?? '0'}`)
        ?.replace(/{{currency}}/g, currencySymbol || '') || `Your points balance: ${points || 0}`
    }</div>`;
    let newContent = jQuery(content);
    const selector = '.appstle-loyalty-point-balance-block';
    setTimeout(() => {
      newContent.insertAfter(jQuery(selector));
    }, 1000);
  };

  const productAcquirePoints = async (widgetSettings, widgetLabels) => {
    const variant = detectVariant();
    if (variant) {
      const totalPoints = (await getAcquirePointsByProduct(window?._ALConfig, variant?.id)) || 0;
      showAcquirePointsBadge(totalPoints, widgetSettings, null, widgetLabels);
    }
  };

  const collectionFeaturedProductsAcquirePoints = (widgetSettings, widgetLabels) => {
    const elements = getStandAloneElements();
    if (elements && elements?.length) {
      elements?.each(async (index, element) => {
        const variantId = Number(jQuery(element).attr('data-variant-id'));
        const productHandle = jQuery(element).attr('data-product-handle');
        let productData = jQuery(element).attr('data-product-data');

        if (typeof productData === 'string') {
          productData = await getProductDetailsByHandle(productHandle);
        } else {
          try {
            productData = productData ? JSON.parse(productData) : [];
          } catch (e) {
            productData = [];
          }
        }

        if (index !== -1) {
          const totalPoints = await getAcquirePointsByFeaturedProductsOrCollections(window?._ALConfig, productData, variantId);
          showAcquirePointsBadge(totalPoints, widgetSettings, element, widgetLabels);
        }
      });
    }
  };

  const acquirePointsBadge = async (widgetSettings, widgetLabels) => {
    const hasLoggedOutLabel = _ALConfig?.widget_setting?.loggedOutFlagAcquirePoints === true;
    if (!hasLoggedOutLabel && !isCustomerEnrolled) return;
    await productAcquirePoints(widgetSettings, widgetLabels);
    await collectionFeaturedProductsAcquirePoints(widgetSettings, widgetLabels);
  };

  const pointBalance = (widgetSettings, widgetLabels) => {
    if (_ALConfig?.customerId) {
      showPointBalance(
        _ALConfig?.customerLoyalty?.availablePoints,
        _ALConfig?.customerLoyalty?.storeCreditBalance,
        widgetSettings,
        widgetLabels
      );
    }
  };

  const showCurrentVipTierBadge = (widgetSettings, widgetLabels) => {
    const showCurrentVipTierBackgroundColor = widgetSettings?.vipTierBadgeBackgroundColor || 'inherit';
    const showCurrentVipTierTextColor = widgetSettings?.vipTierBadgeTextColor || '#000000';

    const content = `<div class="loyalty-current-vip-tier-badge" style="background-color: ${showCurrentVipTierBackgroundColor}; color: ${showCurrentVipTierTextColor};">${
      widgetLabels?.currentVipTierBadgeLabel?.replace('{{current_tier}}', _ALConfig?.customerLoyalty?.currentVipTier) ||
      `${widgetLabels?.vipCurrentTierLabel}: ${_ALConfig?.customerLoyalty?.currentVipTier}`
    }</div>`;
    let newContent = jQuery(content);
    const selector = '.appstle-loyalty-current-vip-tier-block';
    newContent.insertAfter(jQuery(selector));
  };

  const isBlocksAllowedByCustomerTags = (allowedCustomerTags, customerTags) => {
    if (!allowedCustomerTags || !customerTags) return false;
    const allowedCustomersOnly = allowedCustomerTags?.split(',');
    return hasCommonItemsBetweenTwoListItems(customerTags, allowedCustomersOnly);
  };

  const isBlocksBlockRestrictedByCustomerTags = (restrictCustomerTags, customerTags) => {
    if (!restrictCustomerTags || !customerTags) return false;
    const restrictCustomerTagsOnly = restrictCustomerTags?.split(',');
    return hasCommonItemsBetweenTwoListItems(customerTags, restrictCustomerTagsOnly);
  };

  const isShowLoyaltyBlocksAndWidgets = (customerId, customerTags, widgetSettings) => {
    const restrictCustomerTags = widgetSettings?.restrictedCustomersTag || '';
    const allowedCustomerTags = widgetSettings?.allowedCustomersTag || '';

    if (!customerId) {
      return !(restrictCustomerTags || allowedCustomerTags);
    }
    if (restrictCustomerTags && isBlocksBlockRestrictedByCustomerTags(restrictCustomerTags, customerTags)) {
      return false;
    }
    if (allowedCustomerTags && !isBlocksAllowedByCustomerTags(allowedCustomerTags, customerTags)) {
      return false;
    }
    return true;
  };

  const widgetSettings = window?.['_ALConfig']?.widget_setting;
  const widgetLabels = window?.['_ALConfig']?.shop_labels;
  const isShowEmbeddedBlocks = isShowLoyaltyBlocksAndWidgets(
    window?.['_ALConfig']?.customerId,
    window?.['_ALConfig']?.customerTags,
    widgetSettings
  );

  let isLoyaltyBlocksExecuting = false;

  const executeLoyaltyBlocks = async () => {
    if (isLoyaltyBlocksExecuting) return;
    if (!window._ALConfig || typeof window._ALConfig !== 'object') {
      console.warn('[LoyaltyAppCore] _ALConfig not found or invalid. Skipping execution.');
      return;
    }
    isLoyaltyBlocksExecuting = true;
    try {
      const isAuthenticated = _ALConfig?.customerId;
      if (isShowEmbeddedBlocks) {
        await acquirePointsBadge(widgetSettings, widgetLabels);
        if (isAuthenticated) {
          pointBalance(widgetSettings, widgetLabels);
          if (_ALConfig?.customerLoyalty?.currentVipTier) {
            showCurrentVipTierBadge(widgetSettings, widgetLabels);
          }
        }
      }
    } finally {
      isLoyaltyBlocksExecuting = false;
    }
  };

  const rerenderLoyaltyBlocksExecution = async () => {
    if (isShowEmbeddedBlocks && window?._ALConfig) {
      await acquirePointsBadge(widgetSettings, widgetLabels);
    }
  };

  const getIframeSrcDocByRoot = root => `<html>
  <head>
    <script>
      window["Shopify"] = {};
      window["__st"] = ${JSON.stringify(window.__st)};
      window["Shopify"]["shop"] = "${location?.host}";
      window.appstle_public_domain = "${location?.host}";
      window["isAppstleLoyaltyCustomerPortal"] = true;
      window._ALConfig = ${JSON.stringify(window?._ALConfig)};
    </script>
    <link rel="stylesheet" href="${_ALConfig?.widgetCssPath}">
    <script defer src="${_ALConfig?.widgetJsPath}"></script>
  </head>
  <body>
    <div id="${root}" style="overflow:auto;"></div>
  </body>
</html>`;

  const executeLoyaltyRedeemWidgetFeatures = () => {
    const delayedShops = ['k6amz8-jh.myshopify.com', '0z9m39-9y.myshopify.com', 'atomfreshlab.myshopify.com', 'jsx0wp-8t.myshopify.com'];
    const shopsToBeNeedToObserveEntireBody = ['proshave-dk.myshopify.com', 'atomfreshlab.myshopify.com', 'grneo.myshopify.com', 'caretucker.myshopify.com'];
    const currentShop = _ALConfig?.shop || window?.['Shopify']?.shop;
    const shouldDelay = delayedShops.includes(currentShop);
    const shouldObserveEntireBody = shopsToBeNeedToObserveEntireBody.includes(currentShop);
    const runFeatures = () => {
      executeLoyaltyRedeemWidgetBlock();
      observeCartDrawerUpdates();
      if (shouldObserveEntireBody) {
        observeRedeemWidgetDomBodyChanges();
      }
    };
    shouldDelay ? setTimeout(runFeatures, 2000) : runFeatures();
  };

  const createCartWidgetIframeIfNeeded = (iframeId, iframeSrcDoc) => {
    let iframe = document.getElementById(iframeId);
    if (iframe) return iframe;
    iframe = document.createElement('iframe');
    iframe.id = iframeId;
    iframe.className = iframeId;
    document.body.appendChild(iframe);
    const iframeDoc = iframe?.['contentDocument'] || iframe?.['contentWindow']?.document;
    iframeDoc.open();
    iframeDoc.write(getIframeSrcDocByRoot(iframeSrcDoc));
    iframeDoc.close();
    return iframe;
  };

  const executeLoyaltyRedeemWidgetBlock = () => {
    const config = window?._ALConfig;
    const widgetSettings = config?.widget_setting;
    const widgetLabels = config?.shop_labels || {};
    const isDedicatedPage = window?.location?.pathname?.includes(_ALConfig?.proxy_path_prefix || 'app/loyalty');
    const isRedeemWidgetEnabled = widgetSettings?.showCartRedeemWidget !== false;
    if (!isRedeemWidgetEnabled) return;
    const isShowCartWidget = isShowLoyaltyBlocksAndWidgets(config?.customerId, config?.customerTags, widgetSettings);
    if (!isShowCartWidget) return;

    const defaultCartPageCheckoutButtonSelector = '#main-cart-footer .cart__ctas, .cart-page .cart__ctas';
    const defaultCartDrawerCheckoutButtonSelector =
      '#CartDrawer .cart__ctas, .cart-drawer .cart__ctas, .drawer .cart__ctas, .mini-cart .cart__ctas, .drawer__footer .cart__ctas, .drawer__inner .cart__ctas';

    const blockElementSelector = '.appstle-loyalty-redeem-widget-block';
    const cartPageWidgetSelector = widgetSettings?.cartWidgetSelector || defaultCartPageCheckoutButtonSelector || '';
    const cartDrawerWidgetSelector = widgetSettings?.cartDrawerRedeemWidgetSelector || defaultCartDrawerCheckoutButtonSelector || '';
    const cartWidgetPlacement = widgetSettings?.cartWidgetPlacement || 'BEFORE';
    const cartDrawerWidgetPlacement = widgetSettings?.cartDrawerWidgetPlacement || 'BEFORE';

    if (!document?.querySelector(blockElementSelector) && !cartPageWidgetSelector?.trim() && !cartDrawerWidgetSelector?.trim()) return;

    const iframe = createCartWidgetIframeIfNeeded('appstle_loyalty_cart_widget_iframe', 'appstleLoyaltyCartRedeemWidget');
    const isAuthenticated = !!config?.customerId;
    const availablePoints = config?.customerLoyalty?.availablePoints?.toLocaleString() || '0';
    const storeCredits = config?.customerLoyalty?.storeCreditBalance?.toLocaleString() || '0';

    const createWidgetHTML = () => {
      const wrapper = document.createElement('div');
      wrapper.className = 'appstle_loyalty_cart_widget_wrapper';
      const content = isAuthenticated
        ? `
          <div class="loyalty-cart-widget-points-store-credits-info">
            <div class="loyalty-cart-widget-icon">
              ${
                widgetSettings?.cartWidgetRewardIcon
                  ? `
               <img src="${widgetSettings?.cartWidgetRewardIcon}" class="loyalty-cart-widget-icon-image" alt="Image">
              `
                  : `
              <svg class="loyalty-cart-widget-icon-image loyalty-cart-widget-icon-svg" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">
                <path d="M20 12v8a2 2 0 0 1-2 2h-4v-10h6zm-8 0v10h-4a2 2 0 0 1-2-2v-8h6zm8-2h-6v-2h5a1 1 0 0 1 1 1v1zm-8-2v2h-6v-1a1 1 0 0 1 1-1h5zm7-2h-2.586l1.293-1.293a1 1 0 0 0-1.414-1.414L15 5.586 13.707 4.293a1 1 0 1 0-1.414 1.414L13.586 7H7a3 3 0 0 0-3 3v1a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-1a3 3 0 0 0-3-3z" fill="white"/>
              </svg>
              `
              }
            </div>
            <span class="loyalty-cart-widget-available-balance-label">
              ${
                widgetLabels?.cartWidgetAvailableBalanceLabel
                  ?.replace('{{available_points}}', availablePoints)
                  ?.replace('{{store_credits}}', storeCredits)
                  ?.replace('{{currency}}', currencySymbol) || `You have: ${availablePoints} points`
              }
            </span>
          </div>
          <div type="button" class="loyalty-cart-widget-rewards-btn">${widgetLabels?.cartWidgetRewardsButtonLabel || 'Rewards'}</div>
        `
        : `<div class="loyalty-cart-widget-login-message">
              <span class="loyalty-cart-widget-login-message-label">
                <span class="loyalty-cart-widget-login-label">${
                  widgetLabels?.cartWidgetLoginMessageLabel || 'Log in to redeem your points for rewards.'
                }</span>
                <a href="/account/login" class="loyalty-cart-widget-login-link">${widgetLabels?.cartWidgetLoginLinkLabel || 'Log in'}</a>
              </span>
          </div>`;
      wrapper.innerHTML = `<div class="loyalty-cart-widget-button">${content}</div>`;
      return wrapper;
    };

    const injectWidget = target => {
      if (!target) return;
      const widget = createWidgetHTML();
      const rewardsBtn = widget?.querySelector('.loyalty-cart-widget-rewards-btn');

      const openRedeemWidget = () => {
        iframe.classList.add('open');
        iframe.contentWindow?.postMessage('showEarnRedeemWidget', '*');
      };

      if (rewardsBtn) {
        rewardsBtn.classList.add('loyalty-cart-widget-rewards-btn-disabled');
        rewardsBtn.disabled = true;
        const readyHandler = event => {
          if (event?.data === 'loyalty_earn_redeem_widget_ready') {
            rewardsBtn.classList.remove('loyalty-cart-widget-rewards-btn-disabled');
            rewardsBtn.disabled = false;
            setTimeout(() => {
              rewardsBtn.addEventListener('click', openRedeemWidget);
              // ✅ deep-link auto-open support
              if (isAuthenticated && window?.location?.hash === '#appstle-redeem-widget' && !window?.['__appstleRedeemWidgetDeepLinkHandled']) {
                window.__appstleRedeemWidgetDeepLinkHandled = true;
                openRedeemWidget();
              }
            }, 300);
            window.removeEventListener('message', readyHandler);
          }
        };
        window.addEventListener('message', readyHandler);
      }
      const hideHandler = event => {
        if (event?.data === 'hideEarnRedeemWidget') {
          iframe.contentWindow?.postMessage('hideEarnRedeemWidget', '*');
          setTimeout(() => iframe.classList.remove('open'), 300);
        }
      };
      window.addEventListener('message', hideHandler);
      target.setAttribute('data-widget-processed', 'true');
      target.appendChild(widget);
    };

    const createAndInsertBlockElement = (anchorSelector, placementType) => {
      if (!anchorSelector) return null;
      const anchorEl = anchorSelector ? document.querySelector(anchorSelector) : null;
      if (!anchorEl || !anchorEl?.parentNode) return null;

      // Escape special characters in `anchorSelector` to make it valid for querySelector
      const escapedSelector = anchorSelector
        ?.replace(/"/g, '\\"') // Escape double quotes
        ?.replace(/'/g, "\\'") // Escape single quotes
        ?.replace(/\[/g, '\\[') // Escape opening square brackets
        ?.replace(/]/g, '\\]'); // Escape closing square brackets;

      const existingBlock = document.querySelector(`.appstle-loyalty-redeem-widget-block[data-for-selector="${escapedSelector}"]`);
      if (existingBlock) return existingBlock;

      const block = document.createElement('div');
      block.className = 'appstle-loyalty-redeem-widget-block';
      block.id = 'appstle-loyalty-redeem-widget-block';
      block.setAttribute('data-for-selector', anchorSelector);

      if (placementType === 'CHILD') {
        anchorEl.appendChild(block);
      } else if (placementType === 'BEFORE') {
        anchorEl.parentNode.insertBefore(block, anchorEl);
      } else if (placementType === 'AFTER') {
        anchorEl.parentNode.insertBefore(block, anchorEl.nextSibling);
      }

      return block;
    };

    const injectThroughBlocks = () => {
      const blocks = document.querySelectorAll(`${blockElementSelector}:not([data-widget-processed])`);
      blocks?.forEach(block => {
        block.setAttribute('embed-through-app-block', 'true');
        injectWidget(block);
      });
    };

    const injectThroughSelectors = () => {
      if (cartPageWidgetSelector && cartPageWidgetSelector?.trim() !== '') {
        const hasAppBlock = document.querySelector(blockElementSelector);
        if (hasAppBlock) return; /* if app block is added then selector approach will not display */
        const block = createAndInsertBlockElement(cartPageWidgetSelector, cartWidgetPlacement);
        if (block && !block.hasAttribute('data-widget-processed')) {
          injectWidget(block);
        }
      }
      if (cartDrawerWidgetSelector && cartDrawerWidgetSelector?.trim() !== '') {
        const block = createAndInsertBlockElement(cartDrawerWidgetSelector, cartDrawerWidgetPlacement);
        if (block && !block.hasAttribute('data-widget-processed')) {
          injectWidget(block);
        }
      }
    };
    const startRendering = () => {
      injectThroughBlocks();
      injectThroughSelectors();
    };

    startRendering();
  };

  const observeRedeemWidgetDomBodyChanges = (observeSelector = 'body') => {
    const target = document.querySelector(observeSelector);
    if (!target) return;
    const observer = new MutationObserver(mutations => {
      for (const mutation of mutations) {
        if (mutation.type === 'childList' || mutation.type === 'subtree') {
          executeLoyaltyRedeemWidgetBlock();
        }
      }
    });
    observer.observe(target, { childList: true, subtree: true });
  };

  let cartDrawerObserver = null;

  const CART_DRAWER_SELECTORS = [
    '#CartDrawer',
    '.cart-drawer',
    '#mini-cart',
    '.drawer',
    '.quick-cart',
    '#corner-cowi-open-wrapper',
    '[data-cart-drawer]',
    '[data-drawer="cart"]',
  ];

  const observeCartDrawerUpdates = () => {
    if (cartDrawerObserver && cartDrawerObserver?._target?.isConnected) return;

    const setupObserverOnTarget = target => {
      if (!target) return;
      cartDrawerObserver?.disconnect();

      let tick;
      const trigger = () => {
        clearTimeout(tick);
        tick = setTimeout(run, 120);
      };

      const run = () => {
        const widget = target?.querySelector?.('.appstle_loyalty_cart_widget_wrapper');
        if (!widget) {
          executeLoyaltyRedeemWidgetBlock();
        }
        window.postMessage('loyalty_earn_redeem_widget_ready', '*');
      };

      const observer = new MutationObserver(mutations => {
        for (const m of mutations) {
          if (
            m?.type === 'childList' ||
            (m?.type === 'attributes' &&
              (m?.attributeName === 'class' ||
                m?.attributeName === 'open' ||
                m?.attributeName === 'aria-hidden' ||
                m?.attributeName === 'style' ||
                (m?.attributeName && m?.attributeName?.startsWith('data-'))))
          ) {
            trigger();
            break;
          }
        }
      });

      const options = {
        childList: true,
        subtree: true,
        attributes: true,
        attributeFilter: ['class', 'open', 'aria-hidden', 'style'],
      };

      observer.observe(target, options);

      if (target.shadowRoot) {
        observer.observe(target.shadowRoot, options);
      }

      observer._target = target;
      cartDrawerObserver = observer;
    };

    const findDrawerCandidates = () =>
      Array.from(new Set(CART_DRAWER_SELECTORS.flatMap(sel => Array.from(document.querySelectorAll(sel)))));

    const tryInit = () => {
      const candidates = findDrawerCandidates();
      if (!candidates.length) return false;

      const preferred = candidates.find(n => n.hasAttribute?.('open') || n.hasAttribute?.('aria-hidden')) || candidates[0];

      setupObserverOnTarget(preferred);
      return true;
    };

    if (!tryInit()) {
      const bodyObserver = new MutationObserver((_, o) => {
        if (tryInit()) o.disconnect();
      });
      bodyObserver.observe(document.body, { childList: true, subtree: true });
    }
  };

  const observeChanges = () => {
    let lastUrl = location.href;
    let timeout;
    const observed = new WeakSet();
    let lastVariantId = getCurrentProductFormVariantId() || getCurrentPageURL()?.searchParams?.get('variant') || null;
    let lastQuantity = getSelectedProductQuantity();

    const execute = type => {
      clearTimeout(timeout);
      timeout = setTimeout(async () => {
        await rerenderLoyaltyBlocksExecution();
      }, 50);
    };
    const checkState = () => {
      const currentUrl = location.href;
      const currentVariantId = getCurrentProductFormVariantId() || getCurrentPageURL()?.searchParams?.get('variant') || null;
      const currentQuantity = getSelectedProductQuantity();

      if (currentUrl !== lastUrl) {
        lastUrl = currentUrl;
        lastVariantId = currentVariantId;
        lastQuantity = currentQuantity;
        execute('url');
        return;
      }

      if (currentVariantId !== lastVariantId) {
        lastVariantId = currentVariantId;
        execute('variant');
      }

      if (currentQuantity !== lastQuantity) {
        lastQuantity = currentQuantity;
        execute('quantity');
      }
    };
    window.addEventListener('popstate', checkState);
    window.addEventListener('hashchange', checkState);
    setInterval(checkState, 1000);

    const observeVariantInput = el => {
      const updateVariant = () => {
        if (location.href === lastUrl) {
          const currentId = el?.value || getCurrentProductFormVariantId();
          if (currentId !== lastVariantId) {
            lastVariantId = currentId;
            execute('variant');
          }
        }
      };
      updateVariant();
      el.addEventListener('input', updateVariant);
      el.addEventListener('change', updateVariant);
      if (el?.tagName === 'INPUT') {
        new MutationObserver(updateVariant).observe(el, { attributes: true, attributeFilter: ['value'] });
      }
      observed.add(el);
    };

    const observeQuantityInput = input => {
      let lastQuantity = Number(input?.value);

      const updateQuantity = () => {
        const val = Number(input?.value);
        if (val !== lastQuantity) {
          lastQuantity = val;
          execute('quantity');
        }
      };

      // 1️⃣ Native typing (all themes)
      input.addEventListener('input', updateQuantity);
      input.addEventListener('change', updateQuantity);

      // 2️⃣ Shopify button-based quantity (Dawn & others)
      const selector = input.closest('quantity-selector') || input.closest('.quantity-selector') || input.closest('[class*="quantity"]');
      if (selector) {
        selector.addEventListener('click', e => {
          // plus / minus buttons
          if (e.target.closest('button')) {
            // wait for Shopify to update value
            requestAnimationFrame(updateQuantity);
          }
        });
      }
      // 3️⃣ Initial sync
      updateQuantity();
      observed.add(input);
    };


    const watchElements = () => {
      if (location.href !== lastUrl) return;
      const productId = window?._ALConfig?.product?.id;
      const targetForm = getEligibleProductForm(productId);

      const variantInput = targetForm?.querySelector('input[name="id"], select[name="id"]');
      if (variantInput && !observed.has(variantInput)) {
        observeVariantInput(variantInput);
      }

      if (_ALConfig?.['isProductPage']) {
        const quantityInput =
          targetForm?.querySelector('input[name="quantity"], .quantity__input, [aria-label="Quantity"]') ||
          document.querySelector('input[name="quantity"], .quantity__input, [aria-label="Quantity"]');
        if (quantityInput && !observed.has(quantityInput)) {
          observeQuantityInput(quantityInput);
        }
      }
    };
    watchElements();
    new MutationObserver(watchElements).observe(document.body, { childList: true, subtree: true });
  };

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', executeLoyaltyFeatures, { once: true });
  } else {
    executeLoyaltyFeatures();
  }
})();
