/* ClawClaw.tech Buy Modal — 购买组件
 * 零依赖，单文件 JS
 * 用法：
 *   1. <link rel="stylesheet" href="/assets/buy-modal.css">
 *   2. <script src="/assets/buy-modal.js" defer></script>
 *   3. 任意元素加 class="clawclaw-buy-btn" + data-product="<product-key>"
 *      或调用 window.CCBuy.open({product: 'voice-memo'})
 */
(function () {
  'use strict';

  const API = '/license/license-server.php';
  let state = {
    product: null,
    productInfo: null,
    email: '',
    channel: 'usdt',
    orderId: null,
    pollTimer: null,
    xunhuEnabled: false,
  };

  // ── 工具 ──
  function $(sel, root) { return (root || document).querySelector(sel); }
  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, m => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[m]);
  }
  async function api(action, opts) {
    opts = opts || {};
    const url = API + (action.indexOf('?') >= 0 ? action : '?action=' + action);
    const fetchOpts = { method: opts.method || 'GET', headers: {} };
    if (opts.body) {
      fetchOpts.headers['Content-Type'] = 'application/json';
      fetchOpts.body = JSON.stringify(opts.body);
    }
    const r = await fetch(url, fetchOpts);
    return r.json();
  }
  async function copyText(text) {
    try {
      await navigator.clipboard.writeText(text);
      return true;
    } catch (e) {
      // 降级：临时 textarea
      const ta = document.createElement('textarea');
      ta.value = text;
      ta.style.position = 'fixed';
      ta.style.opacity = '0';
      document.body.appendChild(ta);
      ta.select();
      try { document.execCommand('copy'); document.body.removeChild(ta); return true; }
      catch (e2) { document.body.removeChild(ta); return false; }
    }
  }
  function fmtCountdown(sec) {
    const m = Math.floor(sec / 60);
    const s = sec % 60;
    return m + ':' + (s < 10 ? '0' + s : s);
  }

  // ── 打开 Modal ──
  async function open(opts) {
    if (!opts || !opts.product) {
      console.error('[CCBuy] 需要 product 参数');
      return;
    }
    state.product = opts.product;
    state.email = opts.email || '';
    state.channel = 'usdt';
    state.orderId = null;
    if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }

    // 拉取产品信息
    try {
      const info = await api('info&product=' + encodeURIComponent(opts.product));
      if (info.error) { alert(info.error); return; }
      state.productInfo = info;
      state.xunhuEnabled = !!info.xunhuEnabled;
      if (!state.xunhuEnabled) state.channel = 'usdt';
    } catch (e) {
      alert('无法获取产品信息：' + e.message);
      return;
    }

    showModal();
    renderStep1();
  }

  // ── 渲染 Modal 容器 ──
  function showModal() {
    let mask = document.getElementById('ccbm-mask');
    if (!mask) {
      mask = document.createElement('div');
      mask.id = 'ccbm-mask';
      mask.className = 'ccbm-mask';
      document.body.appendChild(mask);
    }
    mask.innerHTML = `
      <div class="ccbm-modal">
        <button class="ccbm-close" aria-label="关闭">×</button>
        <div class="ccbm-body" id="ccbm-body"></div>
      </div>
    `;
    mask.classList.add('ccbm-show');
    document.body.style.overflow = 'hidden';

    $('.ccbm-close', mask).addEventListener('click', close);
    mask.addEventListener('click', e => { if (e.target === mask) close(); });
  }

  function close() {
    const mask = document.getElementById('ccbm-mask');
    if (mask) mask.classList.remove('ccbm-show');
    document.body.style.overflow = '';
    if (state.pollTimer) { clearInterval(state.pollTimer); state.pollTimer = null; }
  }

  function setBody(html) {
    const body = document.getElementById('ccbm-body');
    if (body) body.innerHTML = html;
  }

  // ── 步骤 1：邮箱 + 选通道 ──
  function renderStep1() {
    const p = state.productInfo;
    const xunhuHtml = state.xunhuEnabled ? `
      <div class="ccbm-channel${state.channel === 'xunhu' ? ' ccbm-selected' : ''}" data-channel="xunhu">
        <div class="ccbm-channel-icon">💰</div>
        <div class="ccbm-channel-name">微信/支付宝</div>
        <div class="ccbm-channel-desc">¥${p.price}</div>
      </div>
    ` : `
      <div class="ccbm-channel ccbm-disabled" title="未启用">
        <div class="ccbm-channel-icon">💰</div>
        <div class="ccbm-channel-name">微信/支付宝</div>
        <div class="ccbm-channel-desc">暂未启用</div>
      </div>
    `;
    setBody(`
      <div class="ccbm-product-badge">${escapeHtml(p.name)} · 激活码</div>
      <h2 class="ccbm-title">购买激活码</h2>
      <p class="ccbm-subtitle">${escapeHtml(p.desc || '')}</p>
      <div class="ccbm-steps">
        <div class="ccbm-step ccbm-active"></div>
        <div class="ccbm-step"></div>
        <div class="ccbm-step"></div>
      </div>
      <label class="ccbm-label">邮箱地址（用于接收激活码）</label>
      <input type="email" class="ccbm-input" id="ccbm-email" placeholder="you@example.com" value="${escapeHtml(state.email)}">
      <label class="ccbm-label">选择支付方式</label>
      <div class="ccbm-channels">
        ${xunhuHtml}
        <div class="ccbm-channel${state.channel === 'usdt' ? ' ccbm-selected' : ''}" data-channel="usdt">
          <div class="ccbm-channel-icon">⚡</div>
          <div class="ccbm-channel-name">USDT (TRC-20)</div>
          <div class="ccbm-channel-desc">${p.priceUsdt} USDT</div>
        </div>
      </div>
      <button class="ccbm-submit" id="ccbm-next">下一步</button>
    `);

    // 通道选择
    document.querySelectorAll('.ccbm-channel[data-channel]').forEach(el => {
      el.addEventListener('click', () => {
        if (el.classList.contains('ccbm-disabled')) return;
        document.querySelectorAll('.ccbm-channel').forEach(c => c.classList.remove('ccbm-selected'));
        el.classList.add('ccbm-selected');
        state.channel = el.dataset.channel;
      });
    });

    $('#ccbm-next').addEventListener('click', createOrder);
  }

  // ── 创建订单 ──
  async function createOrder() {
    const email = $('#ccbm-email').value.trim();
    if (!email || !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      alert('请输入有效的邮箱地址');
      return;
    }
    state.email = email;

    const btn = $('#ccbm-next');
    btn.disabled = true;
    btn.innerHTML = '<span class="ccbm-spinner"></span>创建订单中...';

    try {
      const r = await api('create-order', {
        method: 'POST',
        body: { product: state.product, email: email, channel: state.channel }
      });
      if (r.error) { alert(r.error); btn.disabled = false; btn.textContent = '下一步'; return; }
      state.orderId = r.orderId;
      if (state.channel === 'usdt') {
        renderStep2Usdt(r);
      } else {
        renderStep2Xunhu(r);
      }
    } catch (e) {
      alert('创建订单失败：' + e.message);
      btn.disabled = false;
      btn.textContent = '下一步';
    }
  }

  // ── 步骤 2：USDT 支付 ──
  function renderStep2Usdt(order) {
    const p = state.productInfo;
    setBody(`
      <div class="ccbm-product-badge">${escapeHtml(p.name)} · USDT 支付</div>
      <h2 class="ccbm-title">扫码支付 ${order.amount} USDT</h2>
      <p class="ccbm-subtitle">使用支持 TRC-20 的钱包扫描下方二维码</p>
      <div class="ccbm-steps">
        <div class="ccbm-step ccbm-done"></div>
        <div class="ccbm-step ccbm-active"></div>
        <div class="ccbm-step"></div>
      </div>
      <div style="text-align:center;">
        <div class="ccbm-qr-wrap"><img src="${escapeHtml(order.qrUrl)}" alt="USDT 收款二维码"></div>
      </div>
      <div class="ccbm-label">收款地址（TRC-20）</div>
      <div class="ccbm-wallet">
        <div class="ccbm-wallet-addr">${escapeHtml(order.wallet)}</div>
        <button class="ccbm-copy-btn" id="ccbm-copy-addr">复制</button>
      </div>
      <div class="ccbm-label">支付金额</div>
      <div style="background:#1C1C21;border:1px solid #27272A;border-radius:10px;padding:14px;text-align:center;">
        <span style="color:#22D3EE;font-size:20px;font-weight:700;">${order.amount} USDT</span>
        <span style="color:#71717A;font-size:12px;margin-left:6px;">≈ ¥${p.price}</span>
      </div>
      <div class="ccbm-countdown" id="ccbm-countdown">订单有效期：60:00</div>
      <div class="ccbm-status" id="ccbm-status">
        <span class="ccbm-spinner"></span>正在等待支付确认...
      </div>
    `);

    $('#ccbm-copy-addr').addEventListener('click', async e => {
      const ok = await copyText(order.wallet);
      e.target.textContent = ok ? '已复制' : '失败';
      setTimeout(() => e.target.textContent = '复制', 1500);
    });

    // 倒计时
    let remain = order.expiresIn;
    const cdEl = $('#ccbm-countdown');
    const cdTimer = setInterval(() => {
      remain -= 1;
      if (remain <= 0) {
        clearInterval(cdTimer);
        cdEl.textContent = '订单已过期';
        cdEl.style.color = '#EF4444';
        if (state.pollTimer) clearInterval(state.pollTimer);
        $('#ccbm-status').innerHTML = '订单已过期，请<a href="#" onclick="location.reload();return false" style="color:#22D3EE">重新下单</a>';
        return;
      }
      cdEl.textContent = '订单有效期：' + fmtCountdown(remain);
    }, 1000);

    // 轮询
    startPolling();
  }

  // ── 步骤 2：虎皮椒跳转 ──
  function renderStep2Xunhu(order) {
    const p = state.productInfo;
    setBody(`
      <div class="ccbm-product-badge">${escapeHtml(p.name)} · 微信/支付宝</div>
      <h2 class="ccbm-title">确认支付 ¥${order.amount}</h2>
      <p class="ccbm-subtitle">点击下方按钮跳转到收银台完成支付</p>
      <div class="ccbm-steps">
        <div class="ccbm-step ccbm-done"></div>
        <div class="ccbm-step ccbm-active"></div>
        <div class="ccbm-step"></div>
      </div>
      <div style="background:#1C1C21;border:1px solid #27272A;border-radius:10px;padding:18px;margin:16px 0;">
        <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
          <span style="color:#71717A;font-size:13px;">产品</span>
          <span style="color:#E4E4E7;font-size:13px;font-weight:600;">${escapeHtml(p.name)} 激活码</span>
        </div>
        <div style="display:flex;justify-content:space-between;margin-bottom:10px;">
          <span style="color:#71717A;font-size:13px;">订单号</span>
          <span style="color:#22D3EE;font-size:12px;font-family:monospace;">${escapeHtml(order.orderId)}</span>
        </div>
        <div style="display:flex;justify-content:space-between;border-top:1px solid #27272A;padding-top:10px;margin-top:10px;">
          <span style="color:#A1A1AA;font-size:14px;">应付金额</span>
          <span style="color:#22D3EE;font-size:20px;font-weight:700;">¥${order.amount}</span>
        </div>
      </div>
      <a href="${escapeHtml(order.payUrl)}" class="ccbm-submit" style="display:block;text-decoration:none;text-align:center;line-height:1.4;">前往收银台支付</a>
      <div class="ccbm-status" id="ccbm-status">
        <span class="ccbm-spinner"></span>支付完成后自动返回，请勿关闭此窗口...
      </div>
    `);

    // 轮询订单状态（用户支付完成后虎皮椒会回调）
    startPolling();
  }

  // ── 轮询订单状态 ──
  function startPolling() {
    if (state.pollTimer) clearInterval(state.pollTimer);
    let count = 0;
    const poll = async () => {
      count++;
      if (count > 600) {  // 30 分钟超时
        clearInterval(state.pollTimer);
        state.pollTimer = null;
        return;
      }
      try {
        const r = await api('check-order&orderId=' + encodeURIComponent(state.orderId));
        if (r.status === 'paid') {
          clearInterval(state.pollTimer);
          state.pollTimer = null;
          renderStep3(r);
        } else if (r.status === 'expired') {
          clearInterval(state.pollTimer);
          state.pollTimer = null;
          const s = $('#ccbm-status');
          if (s) s.innerHTML = '<span style="color:#EF4444;">订单已过期，请重新下单</span>';
        }
      } catch (e) {
        console.error('[CCBuy] 轮询失败:', e);
      }
    };
    state.pollTimer = setInterval(poll, 3000);
    poll();  // 立即执行一次
  }

  // ── 步骤 3：显示激活码 ──
  function renderStep3(result) {
    const p = state.productInfo;
    const emailTip = result.emailSent
      ? `<div class="ccbm-email-tip">✓ 激活码已发送到 <strong>${escapeHtml(result.email)}</strong></div>`
      : `<div class="ccbm-email-tip" style="background:rgba(245,158,11,0.08);border-color:rgba(245,158,11,0.3);color:#FCD34D;">⚠ 邮件发送失败，请务必复制保存激活码</div>`;
    setBody(`
      <div class="ccbm-product-badge">${escapeHtml(p.name)} · 支付成功</div>
      <h2 class="ccbm-title">激活成功</h2>
      <p class="ccbm-subtitle">您的激活码已生成，请妥善保存</p>
      <div class="ccbm-steps">
        <div class="ccbm-step ccbm-done"></div>
        <div class="ccbm-step ccbm-done"></div>
        <div class="ccbm-step ccbm-done"></div>
      </div>
      <div class="ccbm-code-box">
        <div class="ccbm-code-label">您的激活码</div>
        <div class="ccbm-code" id="ccbm-code">${escapeHtml(result.activationCode)}</div>
        <div class="ccbm-code-actions">
          <button class="ccbm-code-btn" id="ccbm-copy-code">📋 复制激活码</button>
        </div>
      </div>
      ${emailTip}
      <div style="background:#1C1C21;border:1px solid #27272A;border-radius:8px;padding:14px;margin-top:14px;">
        <div style="color:#71717A;font-size:12px;margin-bottom:8px;">激活方法</div>
        <ol style="color:#E4E4E7;font-size:13px;line-height:1.8;padding-left:20px;margin:0;">
          <li>下载并打开 ${escapeHtml(p.name)} App</li>
          <li>进入「设置」页</li>
          <li>点击「我已有激活码」</li>
          <li>输入上方激活码完成激活</li>
        </ol>
      </div>
      <button class="ccbm-submit" id="ccbm-done" style="background:#27272A;color:#E4E4E7;box-shadow:none;border:1px solid #3F3F46;">完成</button>
    `);

    $('#ccbm-copy-code').addEventListener('click', async e => {
      const ok = await copyText(result.activationCode);
      e.target.textContent = ok ? '✓ 已复制' : '复制失败';
      setTimeout(() => e.target.textContent = '📋 复制激活码', 2000);
    });
    $('#ccbm-done').addEventListener('click', close);
  }

  // ── 自动绑定 class="clawclaw-buy-btn" 的元素 ──
  function autoBind() {
    document.querySelectorAll('.clawclaw-buy-btn').forEach(el => {
      if (el.dataset.ccbmBound) return;
      el.dataset.ccbmBound = '1';
      el.addEventListener('click', e => {
        e.preventDefault();
        const product = el.dataset.product;
        if (!product) {
          // 从 URL 推断产品（如 voice-memo.html → voice-memo）
          const path = location.pathname.split('/').pop() || '';
          const m = path.match(/^([a-z-]+)\.html?$/i);
          if (m) el.dataset.product = m[1];
        }
        if (!el.dataset.product) {
          alert('未配置 product 属性');
          return;
        }
        open({ product: el.dataset.product });
      });
    });
  }

  // ── 暴露 API ──
  window.CCBuy = { open, close };

  // ── DOM ready 后自动绑定 ──
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', autoBind);
  } else {
    autoBind();
  }
})();
