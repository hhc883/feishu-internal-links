(function (wp, config) {
  'use strict';

  const el = wp.element.createElement;
  const { useState } = wp.element;
  const { Button, Notice, Spinner } = wp.components;
  const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
  const blockStore = 'core/block-editor';
  const draftStatuses = ['auto-draft', 'draft', 'pending'];
  const richContentBlocks = new Set([
    'core/paragraph', 'core/heading', 'core/list-item', 'core/list',
    'core/freeform', 'core/verse', 'core/preformatted',
  ]);
  const richCaptionBlocks = new Set([
    'core/image', 'core/table', 'core/gallery', 'core/video', 'core/audio', 'core/embed',
  ]);
  const origin = new URL(config.origin);
  const comparableHost = host => host.replace(/^www\./, '');
  const siteHosts = new Set([comparableHost(origin.hostname), config.peerHostname]);

  function candidateLink(href) {
    if (!/^(?:https?:)?\/\//i.test(href.trim())) return '';
    try {
      const url = new URL(href, origin);
      if (url.username || url.password || !siteHosts.has(comparableHost(url.hostname))) return '';
      return url.origin === origin.origin ? '' : url.href;
    } catch (_) {
      return '';
    }
  }

  function scanBlockContent(block, visit) {
    const changes = {};
    function check(attribute) {
      const value = block.attributes[attribute];
      const next = visit(value);
      if (next !== value) changes[attribute] = next;
    }
    if (richContentBlocks.has(block.name)) {
      check('content');
      if (block.name === 'core/list') check('values');
    }
    if (richCaptionBlocks.has(block.name)) check('caption');
    if (block.name === 'core/table') {
      for (const part of ['head', 'body', 'foot']) {
        const rows = block.attributes[part];
        if (!Array.isArray(rows)) continue;
        let changed = false;
        const nextRows = rows.map(row => {
          if (!Array.isArray(row?.cells)) return row;
          const cells = row.cells.map(cell => {
            if (!cell) return cell;
            const content = visit(cell.content);
            return content === cell.content ? cell : { ...cell, content };
          });
          if (cells.every((cell, index) => cell === row.cells[index])) return row;
          changed = true;
          return { ...row, cells };
        });
        if (changed) changes[part] = nextRows;
      }
    }
    return changes;
  }

  function scanLinks(blocks, visit) {
    const updates = [];
    function changeHTML(value) {
      const isRich = value != null && typeof value.toHTMLString === 'function';
      const html = isRich ? value.toHTMLString() : value;
      if (typeof html !== 'string' || !/<a[\s>]/i.test(html)) return value;
      const template = document.createElement('template');
      template.innerHTML = html;
      let changed = false;
      for (const anchor of template.content.querySelectorAll('a[href]')) {
        const key = candidateLink(anchor.getAttribute('href'));
        if (key && visit(anchor, key)) changed = true;
      }
      if (!changed) return value;
      return isRich ? wp.richText.RichTextData.fromHTMLString(template.innerHTML) : template.innerHTML;
    }
    function walk(items) {
      for (const block of items) {
        const attributes = scanBlockContent(block, changeHTML);
        if (Object.keys(attributes).length) updates.push({ id: block.clientId, attributes });
        walk(block.innerBlocks || []);
      }
    }
    walk(blocks);
    return updates;
  }

  function correctionPlan(blocks, results) {
    const byLink = new Map(results.map(result => [result.link, result]));
    const entries = [];
    const updates = scanLinks(blocks, (anchor, key) => {
      const result = byLink.get(key);
      if (!result) return false;
      entries.push({
        ...result,
        before: anchor.getAttribute('href'),
        label: anchor.textContent.trim(),
      });
      if (result.action === 'replace') {
        anchor.setAttribute('href', result.url);
        return true;
      }
      if (result.action === 'unlink') {
        anchor.replaceWith(...anchor.childNodes);
        return true;
      }
      return false;
    });
    return { entries, updates };
  }

  function readDraft() {
    const editor = wp.data.select('core/editor');
    const id = editor.getCurrentPostId();
    const status = editor.getEditedPostAttribute('status');
    const blocks = wp.data.select(blockStore).getBlocks();
    return { id, status, blocks, snapshot: JSON.stringify(blocks) };
  }

  function describeResult(entry) {
    if (entry.action === 'replace') return '替换为 '+entry.url;
    if (entry.action === 'unlink') return '本站 sitemap 未收录，取消链接';
    if (entry.action === 'keep') return '本站 sitemap 未收录，保留原链接';
    if (entry.reason === 'sitemap') return 'sitemap 未能完整读取，保留原链接';
    return '未能确认候选地址可访问，保留原链接';
  }

  function Panel() {
    const [plan, setPlan] = useState(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');

    async function preview() {
      setPlan(null);
      setMessage('');
      const draft = readDraft();
      if (!draft.id || !draftStatuses.includes(draft.status)) {
        setMessage('请在未发布的文章草稿中使用。');
        return;
      }
      const links = new Set();
      scanLinks(draft.blocks, (_anchor, key) => {
        links.add(key);
        return false;
      });
      if (!links.size) {
        setMessage('没有找到需要检查的跨站链接或域名变体。');
        return;
      }
      setBusy(true);
      try {
        const body = new URLSearchParams({
          action: config.action,
          nonce: config.nonce,
          post_id: draft.id,
          links: JSON.stringify(Array.from(links)),
        });
        const response = await fetch(config.endpoint, { method: 'POST', credentials: 'same-origin', body });
        const data = await response.json();
        if (!response.ok || !data.success) {
          throw new Error(data.data?.message || '检查失败，请刷新后重试。');
        }
        const fresh = readDraft();
        if (fresh.id !== draft.id || fresh.snapshot !== draft.snapshot || !draftStatuses.includes(fresh.status)) {
          setMessage('草稿已变化，请重新检查。');
          return;
        }
        setPlan({ ...correctionPlan(fresh.blocks, data.data.results), id: fresh.id, snapshot: fresh.snapshot });
      } catch (error) {
        setMessage(error.message || '检查失败，请重试。');
      } finally {
        setBusy(false);
      }
    }

    function apply() {
      const fresh = readDraft();
      if (!plan || fresh.id !== plan.id || fresh.snapshot !== plan.snapshot || !draftStatuses.includes(fresh.status)) {
        setPlan(null);
        setMessage('草稿已变化，请重新检查。');
        return;
      }
      const { updateBlockAttributes } = wp.data.dispatch(blockStore);
      plan.updates.forEach(({ id, attributes }) => updateBlockAttributes(id, attributes));
      const count = plan.entries.filter(entry => ['replace', 'unlink'].includes(entry.action)).length;
      setPlan(null);
      setMessage('已处理 '+count+' 条链接，请检查并保存草稿。');
    }

    const title = '草稿链接检查';
    return el(wp.element.Fragment, null,
      el(PluginSidebarMoreMenuItem, { target: 'fil-draft-sidebar' }, title),
      el(PluginSidebar, { name: 'fil-draft-sidebar', title },
        el('div', { style: { padding: '16px', overflowWrap: 'anywhere' } },
          el('p', null, '核对正文中指向 '+config.peerHostname+' 或本站其他域名写法的链接。'),
          el(Button, { variant: 'primary', onClick: preview, disabled: busy }, '检查草稿链接'),
          busy && el(Spinner),
          message && el(Notice, { status: 'info', isDismissible: false }, message),
          plan && el(wp.element.Fragment, null,
            el('p', null, '检查结果（'+plan.entries.length+' 条）'),
            el('ul', null, plan.entries.map((entry, index) => el('li', { key: index, style: { marginBottom: '12px' } },
              entry.label && el('strong', null, entry.label),
              el('div', null, entry.before),
              el('div', null, describeResult(entry))
            ))),
            plan.updates.length > 0 && el(Button, { variant: 'primary', onClick: apply }, '确认处理')
          )
        )
      )
    );
  }

  wp.plugins.registerPlugin('fil-draft-links', { render: Panel, icon: 'admin-links' });
})(window.wp, window.filDraftLinks);
