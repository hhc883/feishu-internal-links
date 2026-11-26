(function (wp, config) {
  'use strict';

  const el = wp.element.createElement;
  const { useState } = wp.element;
  const { Button, Notice, SelectControl, Spinner } = wp.components;
  const { PluginSidebar, PluginSidebarMoreMenuItem } = wp.editPost;
  const blockStore = 'core/block-editor';
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

  function needsLanguagePath(pathname) {
    if (!config.languagePaths) return false;
    const { base, prefixes } = config.languagePaths;
    const path = pathname.replace(/%[0-9a-f]{2}/gi, escape => escape.toUpperCase());
    return path !== base && path.startsWith(base) && !prefixes.some(prefix => (path + '/').startsWith(prefix));
  }

  function candidateLink(href) {
    if (!/^(?:https?:)?\/\//i.test(href.trim())) return '';
    try {
      const url = new URL(href, origin);
      if (url.username || url.password || !siteHosts.has(comparableHost(url.hostname))) return '';
      return url.origin !== origin.origin || needsLanguagePath(url.pathname) ? url.href : '';
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

  function collectResults(blocks, results) {
    const byLink = new Map(results.map(result => [result.link, result]));
    const entries = [];
    scanLinks(blocks, (anchor, key) => {
      const result = byLink.get(key);
      if (!result) return false;
      entries.push({
        ...result,
        action: result.action === 'unverified' ? 'keep' : result.action,
        before: anchor.getAttribute('href'),
        label: anchor.textContent.trim(),
      });
      return false;
    });
    return entries;
  }

  function readPost() {
    const editor = wp.data.select('core/editor');
    const id = editor.getCurrentPostId();
    const blocks = wp.data.select(blockStore).getBlocks();
    return { id, blocks, snapshot: JSON.stringify(blocks) };
  }

  function describeResult(entry) {
    if (entry.action === 'replace') return '替换为 '+entry.url;
    if (entry.reason === 'missing') return '本站 sitemap 未收录';
    if (entry.reason === 'sitemap') return '未能完整读取已配置的 sitemap';
    return '未能确认候选地址可访问';
  }

  function Panel() {
    const [plan, setPlan] = useState(null);
    const [busy, setBusy] = useState(false);
    const [message, setMessage] = useState('');
    const changeCount = plan ? plan.entries.filter(entry => entry.action !== 'keep').length : 0;

    async function preview() {
      setPlan(null);
      setMessage('');
      const post = readPost();
      if (!post.id) {
        setMessage('请先保存文章，再检查链接。');
        return;
      }
      const links = new Set();
      scanLinks(post.blocks, (_anchor, key) => {
        links.add(key);
        return false;
      });
      if (!links.size) {
        setMessage('没有找到需要检查的链接。');
        return;
      }
      setBusy(true);
      try {
        const body = new URLSearchParams({
          action: config.action,
          nonce: config.nonce,
          post_id: post.id,
          links: JSON.stringify(Array.from(links)),
        });
        const response = await fetch(config.endpoint, { method: 'POST', credentials: 'same-origin', body });
        const data = await response.json();
        if (!response.ok || !data.success) {
          throw new Error(data.data?.message || '检查失败，请刷新后重试。');
        }
        const fresh = readPost();
        if (fresh.id !== post.id || fresh.snapshot !== post.snapshot) {
          setMessage('文章内容已变化，请重新检查。');
          return;
        }
        if (!data.data.results.length) {
          setMessage('没有找到需要检查的链接。');
          return;
        }
        setPlan({ entries: collectResults(fresh.blocks, data.data.results), id: fresh.id, snapshot: fresh.snapshot });
      } catch (error) {
        setMessage(error.message || '检查失败，请重试。');
      } finally {
        setBusy(false);
      }
    }

    function chooseAction(index, action) {
      setPlan(current => current && {
        ...current,
        entries: current.entries.map((entry, position) => position === index ? { ...entry, action } : entry),
      });
    }

    function apply() {
      const fresh = readPost();
      if (!plan || fresh.id !== plan.id || fresh.snapshot !== plan.snapshot) {
        setPlan(null);
        setMessage('文章内容已变化，请重新检查。');
        return;
      }
      // The unchanged block snapshot keeps each choice tied to its original occurrence.
      let index = 0;
      const updates = scanLinks(fresh.blocks, (anchor, key) => {
        const entry = plan.entries[index];
        if (entry?.link !== key) return false;
        index++;
        if (entry.action === 'replace') {
          anchor.setAttribute('href', entry.url);
          return true;
        }
        if (entry.action === 'unlink') {
          anchor.replaceWith(...anchor.childNodes);
          return true;
        }
        return false;
      });
      if (index !== plan.entries.length) {
        setPlan(null);
        setMessage('链接已变化，请重新检查。');
        return;
      }
      const { updateBlockAttributes } = wp.data.dispatch(blockStore);
      updates.forEach(({ id, attributes }) => updateBlockAttributes(id, attributes));
      setPlan(null);
      setMessage('已处理 '+changeCount+' 条链接，请检查并保存文章。');
    }

    const title = '文章链接检查';
    return el(wp.element.Fragment, null,
      el(PluginSidebarMoreMenuItem, { target: 'fil-draft-sidebar' }, title),
      el(PluginSidebar, { name: 'fil-draft-sidebar', title },
        el('div', { style: { padding: '16px', overflowWrap: 'anywhere' } },
          el('p', null, '核对正文中指向 '+config.peerHostname+' 的链接，以及域名或语言路径需要调整的本站链接。'),
          el(Button, { variant: 'primary', onClick: preview, disabled: busy }, '检查文章链接'),
          busy && el(Spinner),
          message && el(Notice, { status: 'info', isDismissible: false }, message),
          plan && el(wp.element.Fragment, null,
            el('p', null, '检查结果（'+plan.entries.length+' 条）'),
            el('ul', null, plan.entries.map((entry, index) => {
              const matched = entry.action === 'replace';
              const color = matched ? '#006b3c' : '#a12328';
              return el('li', { key: index, style: { marginBottom: '12px' } },
                entry.label && el('strong', null, entry.label),
                el('div', null, entry.before),
                el('div', { style: { color } },
                  el('strong', null, matched ? '匹配成功' : '匹配失败'),
                  ' · '+describeResult(entry)
                ),
                !matched && el(SelectControl, {
                  label: '处理方式',
                  value: entry.action,
                  options: [
                    { value: 'keep', label: '保留原链接' },
                    { value: 'unlink', label: '移除链接（保留文字和格式）' },
                  ],
                  onChange: action => chooseAction(index, action),
                })
              );
            })),
            changeCount > 0 && el(Button, { variant: 'primary', onClick: apply }, '确认处理')
          )
        )
      )
    );
  }

  wp.plugins.registerPlugin('fil-draft-links', { render: Panel, icon: 'admin-links' });
})(window.wp, window.filDraftLinks);
