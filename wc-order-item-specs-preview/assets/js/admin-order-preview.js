/**
 * WooCommerce 订单商品规格与发货明细预览交互脚本
 */
(function ($) {
    'use strict';

    var toastTimer = null;
    var $zoomFloating = null;

    /**
     * 显示全局轻量 Toast 提示
     */
    function showToast(message) {
        var $toast = $('#wc-oisp-toast');
        if (!$toast.length) {
            $toast = $('<div id="wc-oisp-toast" class="wc-oisp-toast"></div>').appendTo('body');
        }

        $toast.html('<span class="wc-oisp-toast-icon">✓</span> <span class="wc-oisp-toast-msg">' + message + '</span>');
        $toast.addClass('wc-oisp-toast-show');

        if (toastTimer) {
            clearTimeout(toastTimer);
        }

        toastTimer = setTimeout(function () {
            $toast.removeClass('wc-oisp-toast-show');
        }, 2000);
    }

    /**
     * 现代与兼容双模式复制到剪贴板
     */
    function copyToClipboard(text, successMessage) {
        if (!text) {
            return;
        }

        var msg = successMessage || (window.wcOispI18n ? window.wcOispI18n.copied : '已复制到剪贴板');

        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(text).then(function () {
                showToast(msg);
            }).catch(function () {
                fallbackCopy(text, msg);
            });
        } else {
            fallbackCopy(text, msg);
        }
    }

    /**
     * 传统 execCommand 兜底复制
     */
    function fallbackCopy(text, msg) {
        var textArea = document.createElement('textarea');
        textArea.value = text;
        textArea.style.position = 'fixed';
        textArea.style.left = '-999999px';
        textArea.style.top = '-999999px';
        document.body.appendChild(textArea);
        textArea.focus();
        textArea.select();

        try {
            var successful = document.execCommand('copy');
            if (successful) {
                showToast(msg);
            } else {
                showToast(window.wcOispI18n ? window.wcOispI18n.copyFailed : '复制失败，请手动选择');
            }
        } catch (err) {
            showToast(window.wcOispI18n ? window.wcOispI18n.copyFailed : '复制失败，请手动选择');
        }

        document.body.removeChild(textArea);
    }

    /**
     * 全局悬停放大浮层初始化
     */
    function getZoomFloating() {
        if (!$zoomFloating || !$zoomFloating.length) {
            $zoomFloating = $('<div id="wc-oisp-floating-zoom" class="wc-oisp-floating-zoom"><img src="" alt="" /></div>').appendTo('body');
        }
        return $zoomFloating;
    }

    $(document).ready(function () {
        // 1. 点击可复制元素（商品ID、规格编号、SKU）一键快速复制
        $(document).on('click', '.wc-oisp-copyable, .wc-oisp-id-chip', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var copyText = $(this).attr('data-copy');
            var title = $(this).attr('title') || '信息';
            copyToClipboard(copyText, '已复制: ' + copyText);
        });

        // 2. 点击复制发货地址按钮
        $(document).on('click', '.wc-oisp-copy-address-btn', function (e) {
            e.preventDefault();
            e.stopPropagation();
            var copyText = $(this).attr('data-copy');
            copyToClipboard(copyText, '已复制发货地址');
        });

        // 3. 点击切换地址信息展开 / 收起
        $(document).on('click', '.wc-oisp-toggle-address-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $drawer = $btn.closest('.wc-oisp-shipping-wrapper').find('.wc-oisp-address-drawer');
            var isExpanded = $btn.attr('aria-expanded') === 'true';

            if (isExpanded) {
                $drawer.addClass('wc-oisp-drawer-collapsed');
                $btn.attr('aria-expanded', 'false');
                $btn.find('.wc-oisp-address-label').text(window.wcOispI18n ? window.wcOispI18n.expandAddress : '展开 ▼');
            } else {
                $drawer.removeClass('wc-oisp-drawer-collapsed');
                $btn.attr('aria-expanded', 'true');
                $btn.find('.wc-oisp-address-label').text(window.wcOispI18n ? window.wcOispI18n.collapseAddress : '收起 ▲');
            }
        });

        // 4. 点击展开多商品折叠项
        $(document).on('click', '.wc-oisp-toggle-items-btn', function (e) {
            e.preventDefault();
            var $btn = $(this);
            var $container = $btn.closest('.wc-oisp-container');
            var $hiddenRows = $container.find('.wc-oisp-item-row.wc-oisp-item-collapsed');
            var remaining = $btn.attr('data-remaining');

            if ($hiddenRows.length > 0) {
                // 展开全部
                $container.find('.wc-oisp-item-row').removeClass('wc-oisp-item-collapsed').addClass('wc-oisp-item-revealed');
                $btn.text(window.wcOispI18n ? window.wcOispI18n.collapseItems : '收起多余商品 ▲');
            } else {
                // 收起
                $container.find('.wc-oisp-item-row.wc-oisp-item-revealed').addClass('wc-oisp-item-collapsed').removeClass('wc-oisp-item-revealed');
                var tpl = window.wcOispI18n ? window.wcOispI18n.expandItems : '展开其余 %d 件商品 ▼';
                $btn.text(tpl.replace('%d', remaining));
            }
        });

        // 5. 鼠标悬停缩略图，浮动大图放大预览（基于全局 Body 定位，防止被表格 overflow 截断）
        $(document).on('mouseenter', '.wc-oisp-thumb-box', function () {
            var zoomSrc = $(this).attr('data-zoom-src');
            if (!zoomSrc) {
                return;
            }

            var $floating = getZoomFloating();
            $floating.find('img').attr('src', zoomSrc);

            var offset = $(this).offset();
            var boxWidth = $(this).outerWidth();
            var boxHeight = $(this).outerHeight();
            var winWidth = $(window).width();
            var winScrollTop = $(window).scrollTop();

            var previewSize = 200;
            var targetLeft = offset.left + boxWidth + 10;
            var targetTop = offset.top - 10;

            // 防止右侧超出视窗边界
            if (targetLeft + previewSize > winWidth - 20) {
                targetLeft = offset.left - previewSize - 10;
            }

            // 防止顶部超出视窗边界
            if (targetTop < winScrollTop + 10) {
                targetTop = winScrollTop + 10;
            }

            $floating.css({
                left: targetLeft + 'px',
                top: targetTop + 'px'
            }).stop(true, true).fadeIn(120);
        });

        $(document).on('mouseleave', '.wc-oisp-thumb-box', function () {
            if ($zoomFloating) {
                $zoomFloating.stop(true, true).fadeOut(100);
            }
        });
    });
})(jQuery);
