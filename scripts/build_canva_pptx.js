const pptxgen = require('pptxgenjs');
const path = require('path');
const fs = require('fs');
const root = path.resolve(__dirname, '..');
const out = path.join(root, 'templates', 'canva');
const img = name => path.join(root, 'images/defaults', name);
const pink='FF2D8D', soft='FF75B7', white='FFFFFF', dark='080007';
function makeDeck(filename, layout, bgFile, options={}) {
  const pptx = new pptxgen();
  pptx.author='Nikki Wilder'; pptx.subject='Canva-compatible website template'; pptx.title=options.title||'Velvet Vixxen Template'; pptx.company='Velvet Vixxen';
  pptx.defineLayout({name:'CUSTOM',width:layout.w,height:layout.h}); pptx.layout='CUSTOM';
  pptx.theme={headFontFace:'Georgia',bodyFontFace:'Arial',lang:'en-US'};
  const slide=pptx.addSlide(); slide.background={color:dark};
  slide.addImage({path:bgFile,x:0,y:0,w:layout.w,h:layout.h,sizing:'cover'});
  slide.addShape(pptx.ShapeType.rect,{x:0,y:0,w:layout.w*0.55,h:layout.h,fill:{color:dark,transparency:18},line:{color:dark,transparency:100}});
  slide.addText('Nikki Wilder',{x:layout.w*.05,y:layout.h*.06,w:layout.w*.42,h:layout.h*.12,fontFace:'Georgia',italic:true,fontSize:Math.max(26,layout.h*8),color:soft,margin:0,breakLine:false});
  slide.addText('THE VELVET VIXXEN',{x:layout.w*.055,y:layout.h*.17,w:layout.w*.4,h:layout.h*.05,fontFace:'Arial',fontSize:Math.max(9,layout.h*2),charSpacing:4,color:white,margin:0});
  const safe=options.safe||{x:.05,y:.29,w:.38,h:.55};
  slide.addShape(pptx.ShapeType.roundRect,{x:layout.w*safe.x,y:layout.h*safe.y,w:layout.w*safe.w,h:layout.h*safe.h,rectRadius:.08,fill:{color:dark,transparency:82},line:{color:white,transparency:55,dash:'dash',width:1.5}});
  slide.addText('LIVE WEBSITE TEXT / BUTTON SAFE AREA',{x:layout.w*safe.x+.12,y:layout.h*safe.y+.08,w:layout.w*safe.w-.24,h:.3,fontSize:8,color:white,transparency:25,margin:0});
  const ph=options.placeholder||{x:.6,y:.1,w:.33,h:.78};
  slide.addShape(pptx.ShapeType.roundRect,{x:layout.w*ph.x,y:layout.h*ph.y,w:layout.w*ph.w,h:layout.h*ph.h,rectRadius:.12,fill:{color:pink,transparency:88},line:{color:soft,dash:'dash',width:2.5}});
  slide.addText('DROP YOUR\nTRANSPARENT PHOTO\nHERE',{x:layout.w*ph.x+.15,y:layout.h*(ph.y+ph.h/2)-.5,w:layout.w*ph.w-.3,h:1,fontFace:'Arial',bold:true,fontSize:Math.max(12,layout.h*3),color:white,align:'center',valign:'mid',margin:0});
  slide.addText(options.note||'Replace the marked photo area in Canva. Keep website text live.',{x:.25,y:layout.h-.34,w:layout.w-.5,h:.2,fontSize:7,color:white,transparency:20,align:'right',margin:0});
  return pptx.writeFile({fileName:path.join(out,filename)});
}
(async()=>{
  await makeDeck('Hero-Desktop-Canva-Template.pptx',{w:12,h:5},img('hero-pink-room.jpg'),{title:'Hero Desktop',placeholder:{x:.61,y:.1,w:.32,h:.79},safe:{x:.045,y:.28,w:.42,h:.58},note:'Target export: 2400 × 1000'});
  await makeDeck('Hero-Tablet-Canva-Template.pptx',{w:8,h:7},img('hero-pink-room.jpg'),{title:'Hero Tablet',placeholder:{x:.49,y:.12,w:.43,h:.7},safe:{x:.05,y:.47,w:.45,h:.43},note:'Target export: 1600 × 1400'});
  await makeDeck('Hero-Mobile-Canva-Template.pptx',{w:6.75,h:10},img('hero-pink-room.jpg'),{title:'Hero Mobile',placeholder:{x:.2,y:.08,w:.68,h:.5},safe:{x:.06,y:.59,w:.88,h:.35},note:'Target export: 1080 × 1600'});
  await makeDeck('Footer-Desktop-Canva-Template.pptx',{w:12,h:3.6},img('footer-pink-room.jpg'),{title:'Footer Desktop',placeholder:{x:.62,y:.06,w:.3,h:.84},safe:{x:.045,y:.24,w:.42,h:.62},note:'Target export: 2400 × 720'});
  await makeDeck('Gallery-Cover-Canva-Template.pptx',{w:10,h:7.5},img('set-1.jpg'),{title:'Gallery Cover',placeholder:{x:.08,y:.08,w:.84,h:.64},safe:{x:.08,y:.76,w:.84,h:.18},note:'Target export: 1600 × 1200'});
  await makeDeck('Video-Thumbnail-Canva-Template.pptx',{w:12,h:6.75},img('set-3.jpg'),{title:'Video Thumbnail',placeholder:{x:.08,y:.08,w:.84,h:.66},safe:{x:.08,y:.78,w:.84,h:.15},note:'Target export: 1920 × 1080'});
  // Create a simple index deck with instructions.
  const pptx=new pptxgen();pptx.layout='LAYOUT_WIDE';pptx.author='Nikki Wilder';pptx.title='Velvet Vixxen Canva Starter Kit';
  const s=pptx.addSlide();s.background={color:dark};s.addText('Nikki Wilder',{x:.7,y:.55,w:5.2,h:.8,fontFace:'Georgia',italic:true,fontSize:38,color:soft,margin:0});s.addText('THE VELVET VIXXEN · CANVA STARTER KIT',{x:.75,y:1.45,w:6.5,h:.35,fontSize:13,charSpacing:3,color:white,margin:0});s.addText('Six editable starter templates are included as separate PPTX and SVG files.',{x:.75,y:2.2,w:6.5,h:.55,fontSize:22,color:white,margin:0});s.addText('1  Upload the matching PPTX or SVG to Canva\n2  Replace the dashed photo placeholder\n3  Export the finished artwork\n4  Import it in Admin → Canva Templates\n5  Add extra transparent layers in the website Visual Composer',{x:.85,y:3.05,w:6.6,h:2.6,fontSize:18,color:'EEDDE7',breakLine:false,bullet:{type:'bullet'},margin:.08});s.addImage({path:img('canva-hero-preview.jpg'),x:7.7,y:.75,w:5.1,h:5.8,sizing:'cover'});
  await pptx.writeFile({fileName:path.join(out,'Nikki-Wilder-Canva-Starter-Kit.pptx')});
})();
