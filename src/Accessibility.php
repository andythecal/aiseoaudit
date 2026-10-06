<?php
declare(strict_types=1);
namespace Audit;

/** Source-HTML heuristics, separate from SEO scoring and WCAG conformance. */
final class Accessibility {
    const SCOPE = 'Automated checks of fetched HTML only. A pass is not a WCAG conformance result. CSS visibility, rendered JavaScript, contrast, keyboard operation and screen-reader behaviour need manual testing.';
    private $ids=[], $labels=[], $findings=[], $counts=['fail'=>0,'warn'=>0], $partial=false;
    public static function unavailable(string $reason): array {
        return ['version'=>1,'status'=>'not_checked','summary'=>$reason,'scope'=>self::SCOPE,'counts'=>['fail'=>0,'warn'=>0],'findings'=>[],'manualChecks'=>[],'truncated'=>false];
    }
    public function analyze(Html $html): array {
        $this->ids=[];$this->labels=[];$this->findings=[];$this->counts=['fail'=>0,'warn'=>0];
        $all=$html->nodes('//*');$this->partial=count($all)>5000;$nodes=array_slice($all,0,5000);
        foreach($nodes as $el){
            if($el->getAttribute('id')!=='')$this->ids[$el->getAttribute('id')][]=$el;
            if($el->tagName==='label'&&$el->hasAttribute('for'))$this->labels[$el->getAttribute('for')][]=$el;
        }
        $body=$html->nodes('//body')[0]??null;
        if(!$body||(!$this->text($body)&&!$html->nodes('//body//img|//body//input|//body//button|//body//select|//body//textarea|//body//iframe|//body//a[@href]')))
            return self::unavailable('Too little usable HTML was returned to assess accessibility. Check how the page is served and run a new audit.');
        $root=$html->dom->documentElement;
        if(!$html->text('//head/title'))$this->add('document-title','fail',$root,'Page title is missing or empty','A descriptive title helps people identify a page in tabs and assistive technology.','Add a concise title in the document head.','<title>Contact our team | Example</title>');
        if(!trim($root->getAttribute('lang')))$this->add('document-language','fail',$root,'Document language is missing','Screen readers use the page language to select pronunciation.','Set the correct main language on the html element.','<html lang="en">');
        $mains=[];$navs=[];$previous=0;
        foreach($nodes as $el){
            if($this->excluded($el,false))continue;
            $tag=strtolower($el->tagName);$role=$this->role($el);
            if($this->ariaHidden($el)){
                if($this->focusable($el))$this->add('hidden-focus','warn',$el,'Potential focus target inside aria-hidden content','This element may receive focus while absent from the accessibility tree. Styles and runtime behaviour need verification.','Prevent keyboard focus entering hidden content; consider inert for inactive regions.','<div inert>Inactive content</div>');
                continue;
            }
            if(($tag==='main'&&!$role)||$role==='main')$mains[]=$el;
            if(($tag==='nav'&&!$role)||$role==='navigation')$navs[]=$el;
            if(preg_match('/^h([1-6])$/',$tag,$m)){
                $level=(int)$m[1];
                if(!$this->name($el))$this->add('empty-heading','fail',$el,'Heading has no accessible text','Empty headings create unhelpful stops in heading navigation.','Add meaningful heading text or remove the empty heading.','<h2>Delivery information</h2>');
                if($previous&&$level>$previous+1)$this->add('heading-order','warn',$el,'Heading level skips a step','The source hierarchy may be difficult to follow. Review it in context.','Choose levels that reflect the content hierarchy.','<h2>Services</h2><h3>Website audits</h3>');
                $previous=$level;
            }
            $type=strtolower($el->getAttribute('type'));
            $field=in_array($tag,['input','select','textarea'],true)&&!($tag==='input'&&in_array($type,['hidden','submit','reset','button','image'],true));
            $action=$tag==='button'||($tag==='a'&&$el->hasAttribute('href'))||($tag==='input'&&in_array($type,['submit','reset','button','image'],true))||in_array($role,['button','link','checkbox','radio','switch','combobox','textbox','searchbox','slider','spinbutton'],true);
            if(($field||$action)&&!$this->name($el))$this->add($field?'form-label':'control-name',$this->partial?'warn':'fail',$el,$field?'Form control has no accessible label':'Interactive element has no accessible name','No usable label, ARIA name, appropriate text or native alternative was found. Placeholder text alone is not a label.','Use visible associated labels for fields and meaningful text for links and buttons.','<label for="email">Email address</label><input id="email" type="email">');
            if($tag==='img'&&!$el->hasAttribute('alt')&&!in_array($role,['none','presentation'],true)&&!$this->name($el,false))$this->add('image-alt','fail',$el,'Image has no text alternative','No alt attribute or explicit accessible name was found. Empty alt text is valid for decorative images.','Describe informative images briefly. Give purely decorative images alt="".','<img src="team.jpg" alt="Our support team">');
            if($tag==='iframe'&&!$this->name($el))$this->add('frame-name','fail',$el,'Embedded frame has no accessible name','Users need a name to understand embedded content.','Add a descriptive title to the iframe.','<iframe title="Office location map" src="..."></iframe>');
            if($tag==='table'&&!in_array($role,['none','presentation'],true)&&!$el->getElementsByTagName('th')->length)$this->add('table-headers','warn',$el,'Table has no header cells','Data tables usually need header relationships. This could be a layout table, so review its purpose.','Use th and appropriate scope for data headers; avoid tables for page layout.','<th scope="col">Product</th>');
            if($el->hasAttribute('tabindex')&&(int)$el->getAttribute('tabindex')>0)$this->add('tab-order','warn',$el,'Positive tabindex may disrupt focus order','A positive tabindex changes the natural keyboard navigation sequence.','Prefer document order and native controls; test the full keyboard path.','<button type="button">Open menu</button>');
            if($el->hasAttribute('onclick')&&!in_array($tag,['a','button','input','select','textarea','summary'],true)&&!$role)$this->add('custom-control','warn',$el,'Clickable element may lack keyboard semantics','An inline click handler appears on a non-native control without an explicit role.','Prefer a native button and verify keyboard operation and visible focus.','<button type="button">Show details</button>');
            if($tag==='a'&&preg_match('/\bskip\b.*\b(content|main|navigation)\b/i',$this->name($el))){
                $href=$el->getAttribute('href');
                if(substr($href,0,1)==='#'&&!isset($this->ids[rawurldecode(substr($href,1))])&&!$this->partial)$this->add('skip-target','fail',$el,'Skip link target is missing','The skip link points to an ID absent from the fetched HTML.','Point the link to main content and verify that focus moves correctly.','<a href="#main">Skip to content</a><main id="main">...</main>');
            }
            $this->checkAria($el,$role);
        }
        if(count($mains)!==1)$this->add('main-landmark','warn',$mains[1]??$body,count($mains)?'Multiple main landmarks need review':'Main landmark is missing','A single main landmark usually identifies the primary page content. Hidden states and document context need manual verification.','Use main, or an equivalent role="main", for primary content.','<main id="main">Page content</main>');
        if(count($navs)>1)foreach($navs as $nav)if(!$this->name($nav,false))$this->add('navigation-label','warn',$nav,'Multiple navigation regions need clear names','Name navigation regions by purpose. Regions with identical links can share a name.','Label navigation so people can distinguish its purpose. Native nav already supplies the role.','<nav aria-label="Primary">...</nav>');
        $status=$this->counts['fail']?'fail':($this->partial?'not_checked':'pass');
        return ['version'=>1,'status'=>$status,'summary'=>$status==='fail'?'Automated accessibility checks found issues to fix.':($status==='pass'?'No definite failures found in the automated HTML checks.':'This page exceeded the HTML check limit; a complete result is unavailable.'),'scope'=>self::SCOPE,'counts'=>$this->counts,'findings'=>$this->findings,'truncated'=>$this->partial||array_sum($this->counts)>count($this->findings),'manualChecks'=>[
            'Check colour contrast, text resizing and reflow at zoom.',
            'Use the keyboard to check every control, visible focus, reading order, menus, dialogs and skip links.',
            'Use a screen reader to check names, announcements, errors and meaningful image descriptions.',
            'Check the rendered page after JavaScript loads, including hidden states and responsive navigation.'
        ]];
    }
    private function add(string $rule,string $status,?\DOMElement $el,string $title,string $detail,string $fix,string $example): void {
        $this->counts[$status]++;
        if(count($this->findings)>=150)return;
        $snippet='';
        if($el){
            $snippet='<'.$el->tagName;
            // Only diagnostic attributes: never copy field values or a whole subtree.
            foreach($el->attributes as $a)if(preg_match('/^(id|class|role|type|for|alt|title|lang|tabindex|hidden|inert|aria-[a-z-]+)$/',$a->name))$snippet.=' '.$a->name.'="'.htmlspecialchars(mb_substr($a->value,0,100),ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8').'"';
            $snippet=mb_substr($snippet,0,500).'>';
        }
        $this->findings[]=['rule'=>$rule,'status'=>$status,'title'=>$title,'detail'=>$detail,'element'=>$el?$el->getNodePath():'document','snippet'=>$snippet,'fix'=>$fix,'example'=>$example,'verify'=>'Inspect this element on the rendered page, then test the relevant task with a keyboard and screen reader.'];
    }
    private function excluded(\DOMElement $el,bool $aria=true): bool {
        for($n=$el;$n instanceof \DOMElement;$n=$n->parentNode){
            if(in_array($n->tagName,['script','style','template','noscript'],true)||$n->hasAttribute('hidden')||$n->hasAttribute('inert')||($n->tagName==='input'&&strtolower($n->getAttribute('type'))==='hidden')||preg_match('/(?:^|;)\s*(?:display\s*:\s*none|visibility\s*:\s*(?:hidden|collapse))\s*(?:!important)?\s*(?:;|$)/i',$n->getAttribute('style')))return true;
            if($aria&&strtolower(trim($n->getAttribute('aria-hidden')))==='true')return true;
        }
        return false;
    }
    private function ariaHidden(\DOMElement $el): bool {
        for($n=$el;$n instanceof \DOMElement;$n=$n->parentNode)if(strtolower(trim($n->getAttribute('aria-hidden')))==='true')return true;
        return false;
    }
    private function focusable(\DOMElement $el): bool {
        if($el->hasAttribute('disabled'))return false;
        if($el->hasAttribute('tabindex'))return (int)$el->getAttribute('tabindex')>=0;
        return in_array($el->tagName,['input','button','select','textarea','iframe','summary'],true)||($el->tagName==='a'&&$el->hasAttribute('href'))||($el->hasAttribute('contenteditable')&&$el->getAttribute('contenteditable')!=='false');
    }
    private function text(\DOMNode $el,bool $includeHidden=false,int $depth=0): string {
        if($depth>40)return '';
        if($el instanceof \DOMText)return mb_substr($el->textContent,0,1000);
        if($el instanceof \DOMElement){
            if(in_array($el->tagName,['script','style','template','noscript'],true)||(!$includeHidden&&$this->excluded($el)))return '';
            if($el->tagName==='img')return $el->getAttribute('alt');
            if(trim($el->getAttribute('aria-label'))!=='')return trim($el->getAttribute('aria-label'));
        }
        $text='';foreach($el->childNodes as $child){$text.=' '.$this->text($child,$includeHidden,$depth+1);if(strlen($text)>2000)break;}
        return Html::clean($text);
    }
    private function name(\DOMElement $el,bool $content=true): string {
        $names=[];foreach(preg_split('/\s+/',trim($el->getAttribute('aria-labelledby')),-1,PREG_SPLIT_NO_EMPTY) as $id)if(isset($this->ids[$id][0]))$names[]=$this->text($this->ids[$id][0],true);
        if($names)return Html::clean(implode(' ',$names));
        if(trim($el->getAttribute('aria-label'))!=='')return trim($el->getAttribute('aria-label'));
        if(in_array($el->tagName,['input','select','textarea','button'],true)){
            foreach($this->labels[$el->getAttribute('id')]??[] as $label)$names[]=$this->text($label);
            for($n=$el->parentNode;$n instanceof \DOMElement;$n=$n->parentNode)if($n->tagName==='label'){$names[]=$this->text($n);break;}
            if(Html::clean(implode(' ',$names)))return Html::clean(implode(' ',$names));
            $type=strtolower($el->getAttribute('type'));
            if($el->tagName==='input'&&in_array($type,['submit','reset'],true))return trim($el->getAttribute('value'))?:ucfirst($type);
            if($el->tagName==='input'&&$type==='button')return trim($el->getAttribute('value'))?:trim($el->getAttribute('title'));
            if($el->tagName==='input'&&$type==='image')return trim($el->getAttribute('alt'))?:trim($el->getAttribute('title'));
            return ($el->tagName==='button'?$this->text($el):'')?:trim($el->getAttribute('title'));
        }
        if($el->tagName==='iframe')return trim($el->getAttribute('title'));
        if(in_array($this->role($el),['combobox','textbox','searchbox','slider','spinbutton'],true))$content=false;
        return ($content?$this->text($el):'')?:trim($el->getAttribute('title'));
    }
    private function role(\DOMElement $el): string {
        $roles=explode(' ','alert alertdialog application article banner blockquote button caption cell checkbox code columnheader combobox complementary contentinfo definition deletion dialog directory document emphasis feed figure form generic grid gridcell group heading img insertion link list listbox listitem log main marquee math menu menubar menuitem menuitemcheckbox menuitemradio meter navigation none note option paragraph presentation progressbar radio radiogroup region row rowgroup rowheader scrollbar search searchbox separator slider spinbutton status strong subscript superscript switch tab table tablist tabpanel term textbox time timer toolbar tooltip tree treegrid treeitem');
        foreach(preg_split('/\s+/',trim($el->getAttribute('role'))) as $role)if(in_array($role,$roles,true))return $role;
        return '';
    }
    private function checkAria(\DOMElement $el,string $role): void {
        if(trim($el->getAttribute('role'))!==''&&!$role)$this->add('aria-role','warn',$el,'Role is not recognised by this checker','No supported concrete WAI-ARIA 1.2 role was found. This may be a misspelling, an abstract role or a newer extension.','Prefer native HTML and check role tokens against the relevant specification.','<nav aria-label="Primary">...</nav>');
        $known=explode(' ','aria-activedescendant aria-atomic aria-autocomplete aria-braillelabel aria-brailleroledescription aria-busy aria-checked aria-colcount aria-colindex aria-colindextext aria-colspan aria-controls aria-current aria-describedby aria-description aria-details aria-disabled aria-dropeffect aria-errormessage aria-expanded aria-flowto aria-grabbed aria-haspopup aria-hidden aria-invalid aria-keyshortcuts aria-label aria-labelledby aria-level aria-live aria-modal aria-multiline aria-multiselectable aria-orientation aria-owns aria-placeholder aria-posinset aria-pressed aria-readonly aria-relevant aria-required aria-roledescription aria-rowcount aria-rowindex aria-rowindextext aria-rowspan aria-selected aria-setsize aria-sort aria-valuemax aria-valuemin aria-valuenow aria-valuetext');
        $refs=['aria-labelledby','aria-describedby','aria-controls','aria-owns','aria-activedescendant','aria-details','aria-errormessage','aria-flowto'];
        $enums=['aria-hidden'=>['true','false','undefined'],'aria-expanded'=>['true','false','undefined'],'aria-checked'=>['true','false','mixed','undefined'],'aria-pressed'=>['true','false','mixed','undefined'],'aria-selected'=>['true','false','undefined'],'aria-disabled'=>['true','false'],'aria-required'=>['true','false'],'aria-readonly'=>['true','false'],'aria-busy'=>['true','false'],'aria-modal'=>['true','false'],'aria-multiline'=>['true','false'],'aria-multiselectable'=>['true','false'],'aria-atomic'=>['true','false'],'aria-live'=>['off','polite','assertive'],'aria-orientation'=>['horizontal','vertical','undefined'],'aria-autocomplete'=>['none','inline','list','both'],'aria-haspopup'=>['false','true','menu','listbox','tree','grid','dialog']];
        foreach($el->attributes as $a){
            $key=$a->name;$value=trim($a->value);
            if(strpos($key,'aria-')===0&&!in_array($key,$known,true))$this->add('aria-attribute','warn',$el,'Unrecognised ARIA attribute: '.$key,'This attribute is not recognised by the checker. It may be misspelled or belong to a newer extension.','Check the attribute spelling and its support in the relevant specification.','aria-label="Search"');

            if(isset($enums[$key])&&$value!==''&&!in_array($value,$enums[$key],true))$this->add('aria-value','fail',$el,'Invalid '.$key.' value','The declared value is outside the supported values for this ARIA attribute.','Use a valid value and keep it in sync with the actual state.','aria-expanded="false"');
            if(in_array($key,['aria-valuenow','aria-valuemin','aria-valuemax','aria-level','aria-colcount','aria-rowcount','aria-colindex','aria-rowindex','aria-colspan','aria-rowspan','aria-posinset','aria-setsize'],true)&&$value!==''){
                $range=in_array($key,['aria-valuenow','aria-valuemin','aria-valuemax'],true);
                $unknown=in_array($key,['aria-colcount','aria-rowcount','aria-setsize'],true)&&$value==='-1';
                $minimum=in_array($key,['aria-colcount','aria-rowcount','aria-setsize','aria-rowspan'],true)?0:1;
                if(!is_numeric($value)||!is_finite((float)$value)||(!$range&&!$unknown&&((float)$value<$minimum||(int)$value!=(float)$value)))$this->add('aria-number','fail',$el,'Invalid numeric '.$key,'This attribute needs an appropriate numeric value.','Check the attribute requirements and supply a valid number.','aria-level="2"');
            }
            if(in_array($key,$refs,true)&&!$this->partial)foreach(preg_split('/\s+/',$value,-1,PREG_SPLIT_NO_EMPTY) as $id)if(!isset($this->ids[$id])||count($this->ids[$id])!==1)$this->add('aria-reference',in_array($key,['aria-labelledby','aria-describedby'],true)?'fail':'warn',$el,'Unresolved or ambiguous '.$key.' reference','The reference "'.mb_substr($id,0,100).'" does not identify exactly one element in fetched HTML. Dynamic targets need browser verification.','Use unique IDs and ensure each target exists when the relationship is needed.','<span id="search-label">Search</span><input aria-labelledby="search-label">');
        }
        $required=['checkbox'=>['aria-checked'],'radio'=>['aria-checked'],'switch'=>['aria-checked'],'menuitemcheckbox'=>['aria-checked'],'menuitemradio'=>['aria-checked'],'combobox'=>['aria-expanded'],'slider'=>['aria-valuenow'],'scrollbar'=>['aria-controls','aria-valuenow'],'heading'=>['aria-level']];
        if($role==='combobox'&&$el->getAttribute('aria-expanded')==='true')$required['combobox'][]='aria-controls';
        foreach($required[$role]??[] as $attribute){
            $native=($attribute==='aria-checked'&&$el->tagName==='input'&&in_array(strtolower($el->getAttribute('type')),['checkbox','radio'],true))||($el->tagName==='select'&&$role==='combobox')||($attribute==='aria-valuenow'&&$el->tagName==='input'&&in_array(strtolower($el->getAttribute('type')),['range','number'],true))||($attribute==='aria-level'&&preg_match('/^h[1-6]$/',$el->tagName));
            if(!$native&&trim($el->getAttribute($attribute))==='')$this->add('aria-required','fail',$el,'Role '.$role.' is missing '.$attribute,'This custom role requires a state, value or relationship absent from source HTML.','Prefer an equivalent native element, or supply required ARIA state and update it as users interact.','<input type="checkbox" id="updates"><label for="updates">Send updates</label>');
        }
    }
}
