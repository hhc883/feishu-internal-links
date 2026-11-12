(function (wp) {
  'use strict';
  const el = wp.element.createElement;
  const { useState } = wp.element;
  const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
  const { Button, Notice } = wp.components;
  const store = 'core/block-editor';
  const config = window.filData || { rules: [], home: '' };
  const maxPerRun = Number.isInteger(config.maxPerRun) && config.maxPerRun >= 0 ? config.maxPerRun : 10;
  const maxPerTarget = Number.isInteger(config.maxPerTarget) && config.maxPerTarget >= 0 ? config.maxPerTarget : 1;
  function canonical(url) {
    try { if (!url) return ''; const u = new URL(url, config.home); u.hash = ''; return u.origin + u.pathname.replace(/\/$/, '') + u.search; } catch (_) { return ''; }
  }
  function contentHTML(value) {
    if (typeof value === 'string') return value;
    if (value && typeof value.toHTMLString === 'function') return value.toHTMLString();
    return null;
  }
  function flatten(blocks) { return blocks.reduce((a, b) => a.concat([b], flatten(b.innerBlocks || [])), []); }
  function compute() {
    const blocks = flatten(wp.data.select(store).getBlocks());
    const used = new Map();
    function countExisting(value, depth) {
      if (depth > 12 || value == null) return;
      const html = contentHTML(value);
      if (html !== null) {
        const d = document.createElement('div'); d.innerHTML = html;
        d.querySelectorAll('a[href]').forEach(a => { const target = canonical(a.getAttribute('href')); if (target) used.set(target, (used.get(target) || 0) + 1); });
      } else if (Array.isArray(value)) value.forEach(item => countExisting(item, depth + 1));
      else if (typeof value === 'object') Object.values(value).forEach(item => countExisting(item, depth + 1));
    }
    blocks.forEach(b => countExisting(b.attributes, 0));
    const current = canonical(window.location.origin + window.location.pathname);
    const post = wp.data.select('core/editor');
    const permalink = post && post.getPermalink ? canonical(post.getPermalink() || '') : '';
    const rules = config.rules.slice().sort((a,b) => b.keyword.length - a.keyword.length);
    const changes = [], matches = [];
    const diagnostics = { paragraphs: 0, textNodes: 0, keywordHits: 0, invalid: 0, self: 0, existing: 0, boundaries: 0, eligible: 0 };
    for (const block of blocks) {
      if (!['core/paragraph', 'core/list-item'].includes(block.name) || contentHTML(block.attributes.content) === null) continue;
      diagnostics.paragraphs++;
      const original = contentHTML(block.attributes.content);
      const box = document.createElement('div'); box.innerHTML = original;
      const walker = document.createTreeWalker(box, NodeFilter.SHOW_TEXT);
      const nodes = []; let blockChanged = false; while (walker.nextNode()) nodes.push(walker.currentNode);
      for (const node of nodes) {
        if (maxPerRun > 0 && matches.length >= maxPerRun) break;
        if (node.parentElement.closest('a, code, pre, script, style')) continue;
        diagnostics.textNodes++;
        const text = node.nodeValue; let pos = 0; const frag = document.createDocumentFragment(); let changed = false;
        while (pos < text.length && (maxPerRun === 0 || matches.length < maxPerRun)) {
          let best = null;
          for (const rule of rules) {
            const target = canonical(rule.url);
            const keywordPresent = text.toLocaleLowerCase().includes(rule.keyword.toLocaleLowerCase());
            if (keywordPresent) diagnostics.keywordHits++;
            if (!target) { if (keywordPresent) diagnostics.invalid++; continue; }
            if (target === current || target === permalink) { if (keywordPresent) diagnostics.self++; continue; }
            if (maxPerTarget > 0 && (used.get(target) || 0) >= maxPerTarget) { if (keywordPresent) diagnostics.existing++; continue; }
            if (keywordPresent) diagnostics.eligible++;
            const escaped = rule.keyword.replace(/[.*+?^${}()|[\]\\]/g, '\\$&');
            const re = new RegExp(escaped, 'gi'); re.lastIndex = pos; let m;
            while ((m = re.exec(text))) {
              const before = m.index ? text[m.index-1] : ''; const after = text[m.index+m[0].length] || '';
              const first = rule.keyword[0], last = rule.keyword[rule.keyword.length-1];
              if ((/[A-Za-z0-9_]/.test(first) && /[A-Za-z0-9_]/.test(before)) || (/[A-Za-z0-9_]/.test(last) && /[A-Za-z0-9_]/.test(after))) continue;
              if (!best || m.index < best.index || (m.index === best.index && m[0].length > best.text.length)) best = { index:m.index, text:m[0], url:rule.url, target:target };
              break;
            }
          }
          if (!best) break;
          frag.appendChild(document.createTextNode(text.slice(pos, best.index)));
          const a = document.createElement('a'); a.href = best.url; a.textContent = best.text; frag.appendChild(a);
          used.set(best.target, (used.get(best.target) || 0) + 1); matches.push({ keyword:best.text, url:best.url }); pos = best.index + best.text.length; changed = true;
        }
        if (changed) { frag.appendChild(document.createTextNode(text.slice(pos))); node.replaceWith(frag); blockChanged = true; }
      }
      if (blockChanged) changes.push({ id:block.clientId, original:original, content:box.innerHTML });
      if (maxPerRun > 0 && matches.length >= maxPerRun) break;
    }
    return { changes, matches, diagnostics, snapshot: JSON.stringify(blocks.map(b => [b.clientId,b.name,contentHTML(b.attributes.content)])), permalink };
  }
  function Panel() {
    const [plan,setPlan] = useState(null); const [message,setMessage] = useState('');
    function preview() { const p=compute(); setPlan(p); setMessage(p.matches.length ? '' : '没有可添加的内链。诊断：段落/列表项 ' + p.diagnostics.paragraphs + '，文本节点 ' + p.diagnostics.textNodes + '，包含关键词 ' + p.diagnostics.keywordHits + '，可匹配 ' + p.diagnostics.eligible + '，地址无效 ' + p.diagnostics.invalid + '，指向本文 ' + p.diagnostics.self + '，目标已存在 ' + p.diagnostics.existing + '。'); }
    function apply() {
      const fresh = compute();
      if (!plan || fresh.snapshot !== plan.snapshot || fresh.permalink !== plan.permalink || JSON.stringify(fresh.matches)!==JSON.stringify(plan.matches)) { setPlan(null); setMessage('文章或匹配结果已变化，请重新识别。'); return; }
      plan.changes.forEach(c => {
        const block = wp.data.select(store).getBlock(c.id);
        const content = block && typeof block.attributes.content !== 'string'
          ? wp.richText.RichTextData.fromHTMLString(c.content) : c.content;
        wp.data.dispatch(store).updateBlockAttributes(c.id, { content });
      });
      setMessage('已添加 '+plan.matches.length+' 个内链。请检查正文并保存草稿。'); setPlan(null);
    }
    return el(wp.element.Fragment, {}, el(PluginSidebarMoreMenuItem, { target:'fil-sidebar' }, '飞书内链'), el(PluginSidebar, { name:'fil-sidebar', title:'飞书内链' }, el('div',{style:{padding:'16px'}},
      el('p',{},'已同步 '+config.rules.length+' 条规则。每次最多添加 '+(maxPerRun || '不限')+' 个链接；每个目标地址每篇最多 '+(maxPerTarget || '不限')+' 次（含已有链接）。'),
      el(Button,{variant:'primary',onClick:preview,disabled:!config.rules.length},'识别内链'),
      message && el(Notice,{status:'info',isDismissible:false},message),
      plan && plan.matches.length > 0 && el(wp.element.Fragment,{},el('p',{},'将添加 '+plan.matches.length+' 个链接：'),el('ul',{},plan.matches.map((m,i)=>el('li',{key:i,style:{marginBottom:'10px',overflowWrap:'anywhere'}},el('strong',{},m.keyword),' → ',m.url))),el(Button,{variant:'primary',onClick:apply},'确认添加'), ' ',el(Button,{variant:'secondary',onClick:()=>setPlan(null)},'取消'))
    )));
  }
  wp.plugins.registerPlugin('feishu-internal-links',{render:Panel,icon:'admin-links'});
})(window.wp);
