const fs = require('fs'); const vm = require('vm'); const assert = require('assert');
class RichTextData { constructor(html){this.html=html} toHTMLString(){return this.html} toJSON(){return this.html} static fromHTMLString(html){return new RichTextData(html)} }
class TextNode {constructor(text,parent){this.nodeValue=text;this.parentElement=parent} replaceWith(fragment){this.parentElement.html=fragment.parts.join('')} }
class Box {set innerHTML(v){this.html=v;this.node=new TextNode(v,this)} get innerHTML(){return this.html} closest(){return null} querySelectorAll(){return Array.from(this.html.matchAll(/<a\s+href="([^"]+)"[^>]*>/g),m=>({getAttribute:()=>m[1]}))} }
function exercise({contents=['residential proxy'], rules=[{keyword:'residential proxy',url:'https://example.com/proxy/'}], maxPerRun, maxPerTarget}={}) {
 const blocks=contents.map((content,i)=>({clientId:String(i),name:'core/paragraph',attributes:{content},innerBlocks:[]}));
 const wp={element:{createElement(){},useState(){}},editPost:{},components:{},richText:{RichTextData},data:{select:s=>s==='core/block-editor'?{getBlocks:()=>blocks}:{getPermalink:()=>''}},plugins:{registerPlugin(){}}};
 const ctx={URL,NodeFilter:{SHOW_TEXT:4},window:{wp,location:{origin:'https://example.com',pathname:'/wp-admin/post.php'},filData:{home:'https://example.com/',rules,maxPerRun,maxPerTarget}},document:{createElement:tag=>tag==='a'?{set href(v){this.url=v},set textContent(v){this.text=v},toString(){return `<a href="${this.url}">${this.text}</a>`}}:new Box(),createTreeWalker:box=>({nextNode(){if(this.seen)return false;this.seen=true;this.currentNode=box.node;return true}}),createDocumentFragment:()=>({parts:[],appendChild(v){this.parts.push(String(v))}}),createTextNode:s=>s}};
 let src=fs.readFileSync(require('path').join(__dirname, '..', 'editor.js'),'utf8').replace('  function Panel() {','  window.testCompute = compute; function Panel() {'); vm.runInNewContext(src,ctx); return ctx.window.testCompute();
}
for (const value of ['residential proxy',new RichTextData('residential proxy')]) assert.strictEqual(exercise({contents:[value]}).matches.length,1);
assert.strictEqual(exercise({contents:['residential proxy residential proxy']}).matches.length,1,'default target limit');
assert.strictEqual(exercise({contents:['residential proxy residential proxy'],maxPerTarget:2}).matches.length,2,'configured target limit');
assert.strictEqual(exercise({contents:['residential proxy residential proxy residential proxy'],maxPerTarget:0,maxPerRun:2}).matches.length,2,'configured run limit');
assert.strictEqual(exercise({contents:['residential proxy residential proxy residential proxy'],maxPerTarget:0,maxPerRun:0}).matches.length,3,'unlimited limits');
assert.strictEqual(exercise({contents:['<a href="https://example.com/proxy/">old</a>','residential proxy'],maxPerTarget:1}).matches.length,0,'existing link counts');
assert.strictEqual(exercise({contents:['<a href="https://example.com/proxy/">old</a>','residential proxy'],maxPerTarget:2}).matches.length,1,'existing link leaves remaining allowance');
console.log('Passed: legacy defaults, rich text, per-run and per-target limits, unlimited, existing links.');
