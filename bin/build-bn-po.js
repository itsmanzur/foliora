/**
 * Build languages/foliora-bn_BD.po from the POT + a msgid map.
 */
const fs = require( 'fs' );
const path = require( 'path' );

const root = path.resolve( __dirname, '..' );
const pot = fs.readFileSync( path.join( root, 'languages', 'foliora.pot' ), 'utf8' );

const t = {
	'Foliora': 'Foliora',
	'https://thereadscope.com/foliora': 'https://thereadscope.com/foliora',
	'A fast, accessible PDF &amp; eBook viewer for WordPress. Bundled PDF.js rendering, shortcode + block embed, and a clean upgrade path to Foliora Pro for EPUB, bookmarks, and reading-progress features.': 'ওয়ার্ডপ্রেসের জন্য একটি দ্রুত, অ্যাকসেসিবল PDF ও ই-বুক ভিউয়ার। বান্ডেল করা PDF.js রেন্ডারিং, শর্টকোড ও ব্লক এমবেড, আর EPUB, বুকমার্ক ও রিডিং-প্রগ্রেসের জন্য Foliora Pro-তে আপগ্রেডের সহজ পথ।',
	'The Read Scope': 'The Read Scope',
	'https://thereadscope.com': 'https://thereadscope.com',
	'Foliora Library': 'Foliora লাইব্রেরি',
	'A grid of Media Library PDFs.': 'মিডিয়া লাইব্রেরির PDF-এর একটি গ্রিড।',
	'Media': 'মিডিয়া',
	'Foliora Viewer': 'Foliora ভিউয়ার',
	'Embed a PDF with the Foliora viewer.': 'Foliora ভিউয়ার দিয়ে একটি PDF এমবেড করুন।',
	'Grid': 'গ্রিড',
	'Columns': 'কলাম',
	'Per page': 'প্রতি পাতায়',
	'Order by': 'সাজানো',
	'Date': 'তারিখ',
	'Title': 'শিরোনাম',
	'Modified': 'পরিবর্তিত',
	'Order': 'ক্রম',
	'Newest first': 'নতুন আগে',
	'Oldest first': 'পুরনো আগে',
	'Search box': 'সার্চ বক্স',
	'Show': 'দেখান',
	'Hide': 'লুকান',
	'Document': 'ডকুমেন্ট',
	'Layout': 'লেআউট',
	'PDF file': 'PDF ফাইল',
	'Select a PDF': 'একটি PDF বেছে নিন',
	'Choose a PDF': 'একটি PDF বাছুন',
	'Set as PDF': 'PDF হিসেবে সেট করুন',
	'Select a PDF from the Media Library. If the upload picker shows images only, paste the PDF URL instead.': 'মিডিয়া লাইব্রেরি থেকে একটি PDF বেছে নিন। আপলোড পিকার শুধু ছবি দেখালে PDF-এর URL পেস্ট করুন।',
	'Title caption': 'শিরোনাম ক্যাপশন',
	'Start page': 'শুরুর পাতা',
	'Width': 'প্রস্থ',
	'Height': 'উচ্চতা',
	'Download button': 'ডাউনলোড বাটন',
	'Print button': 'প্রিন্ট বাটন',
	'Choose a PDF from the Media Library.': 'মিডিয়া লাইব্রেরি থেকে একটি PDF বেছে নিন।',
	'Forbidden.': 'অনুমতি নেই।',
	'PDF accessibility checks are turned off.': 'PDF অ্যাকসেসিবিলিটি চেক বন্ধ আছে।',
	'That file is not a PDF attachment.': 'ওই ফাইলটি একটি PDF অ্যাটাচমেন্ট নয়।',
	'Foliora Dashboard': 'Foliora ড্যাশবোর্ড',
	'Dashboard': 'ড্যাশবোর্ড',
	'Foliora Documents': 'Foliora ডকুমেন্টস',
	'Documents': 'ডকুমেন্টস',
	'Foliora Settings': 'Foliora সেটিংস',
	'Settings': 'সেটিংস',
	'Loading document…': 'ডকুমেন্ট লোড হচ্ছে…',
	'This document could not be loaded.': 'এই ডকুমেন্ট লোড করা যায়নি।',
	'Page %1$s of %2$s': 'পাতা %1$s / %2$s',
	'This document is password-protected.': 'এই ডকুমেন্ট পাসওয়ার্ড-সুরক্ষিত।',
	'Incorrect password.': 'পাসওয়ার্ড ভুল।',
	'Unlock': 'আনলক',
	'Password': 'পাসওয়ার্ড',
	'Table of contents': 'সূচিপত্র',
	'Page thumbnails': 'পাতার থাম্বনেইল',
	'Document outline': 'ডকুমেন্ট আউটলাইন',
	'Close panel': 'প্যানেল বন্ধ করুন',
	'Fit page': 'পাতায় ফিট',
	'Fit width': 'প্রস্থে ফিট',
	'Indexed': 'ইনডেক্সড',
	'No text': 'টেক্সট নেই',
	'Tagged': 'ট্যাগড',
	'Untagged': 'আনট্যাগড',
	'This PDF has no accessibility tags. Export it from Word, InDesign, or Acrobat as a tagged PDF so screen readers can follow headings and reading order.': 'এই PDF-এ অ্যাকসেসিবিলিটি ট্যাগ নেই। স্ক্রিন রিডার যেন শিরোনাম ও পড়ার ক্রম অনুসরণ করতে পারে, Word, InDesign বা Acrobat থেকে ট্যাগড PDF হিসেবে এক্সপোর্ট করুন।',
	'Select PDF': 'PDF বেছে নিন',
	'Use this PDF': 'এই PDF ব্যবহার করুন',
	'Copied!': 'কপি হয়েছে!',
	'Shortcode copied': 'শর্টকোড কপি হয়েছে',
	'Dismissed': 'বাতিল',
	'%1$s of %2$s steps complete': '%2$s-এর মধ্যে %1$s ধাপ সম্পন্ন',
	'You are set. New documents go in Documents or the Media Library.': 'প্রস্তুত। নতুন ডকুমেন্ট ডকুমেন্টস বা মিডিয়া লাইব্রেরিতে যোগ করুন।',
	'Drop a PDF to embed it': 'এমবেড করতে একটি PDF ফেলুন',
	'Uploading…': 'আপলোড হচ্ছে…',
	'Creating page…': 'পাতা তৈরি হচ্ছে…',
	'Please drop a PDF file.': 'অনুগ্রহ করে একটি PDF ফাইল ফেলুন।',
	'Upload failed. Try the Media Library.': 'আপলোড ব্যর্থ। মিডিয়া লাইব্রেরি ব্যবহার করে দেখুন।',
	'Viewer Defaults': 'ভিউয়ার ডিফল্ট',
	'Default width': 'ডিফল্ট প্রস্থ',
	'Default height': 'ডিফল্ট উচ্চতা',
	'Search and sharing': 'সার্চ ও শেয়ারিং',
	'Site search': 'সাইট সার্চ',
	'Social preview': 'সোশ্যাল প্রিভিউ',
	'Accessibility tags': 'অ্যাকসেসিবিলিটি ট্যাগ',
	'Show the download button on the viewer toolbar. Individual embeds can override this with download="false".': 'ভিউয়ার টুলবারে ডাউনলোড বাটন দেখান। আলাদা এমবেড download="false" দিয়ে এটি ওভাররাইড করতে পারে।',
	'Show the print button on the viewer toolbar. Individual embeds can override this with print="false".': 'ভিউয়ার টুলবারে প্রিন্ট বাটন দেখান। আলাদা এমবেড print="false" দিয়ে এটি ওভাররাইড করতে পারে।',
	'Make embedded PDFs findable in WordPress search and nicer when a page is shared.': 'এমবেড করা PDF ওয়ার্ডপ্রেস সার্চে খুঁজে পাওয়া যাক, আর পেজ শেয়ার করলে প্রিভিউ ভালো দেখাক।',
	'Include text from embedded PDFs in WordPress site search (and in the document library search). Visit Foliora → Documents to extract text.': 'এমবেড করা PDF-এর টেক্সট ওয়ার্ডপ্রেস সাইট সার্চে (এবং ডকুমেন্ট লাইব্রেরি সার্চে) রাখুন। টেক্সট এক্সট্র্যাক্ট করতে Foliora → ডকুমেন্টস খুলুন।',
	'Use the PDF first-page thumbnail as the Open Graph / Twitter image when a post or page embeds Foliora and no SEO plugin already set an image.': 'কোনো পোস্ট বা পেজে Foliora এমবেড থাকলে এবং SEO প্লাগিন ইতিমধ্যে ছবি সেট না করলে PDF-এর প্রথম পাতার থাম্বনেইল Open Graph / Twitter ছবি হিসেবে ব্যবহার করুন।',
	'Warn on Foliora → Documents when a PDF is not tagged. Tagged PDFs include structure (headings, reading order) that screen readers can follow.': 'PDF ট্যাগড না হলে Foliora → ডকুমেন্টসে সতর্ক করুন। ট্যাগড PDF-এ স্ক্রিন রিডার অনুসরণ করতে পারে এমন গঠন (শিরোনাম, পড়ার ক্রম) থাকে।',
	'Embed PDFs with a bundled viewer. No CDN, no account, no API keys.': 'বান্ডেল করা ভিউয়ার দিয়ে PDF এমবেড করুন। কোনো CDN, অ্যাকাউন্ট বা API কী লাগে না।',
	'Pro active': 'Pro চালু',
	'Plain permalinks — share links need Post name': 'সাধারণ পারমালিংক — শেয়ার লিংকের জন্য Post name লাগে',
	'Upgrade to Pro': 'Pro-তে আপগ্রেড',
	'Pick a cover on the right — or drop a PDF — then copy the shortcode.': 'ডানদিকে একটি কভার বেছে নিন — অথবা একটি PDF ফেলুন — তারপর শর্টকোড কপি করুন।',
	'Dismiss this notice.': 'এই নোটিশ বন্ধ করুন।',
	'PDFs': 'PDF',
	'Embeds': 'এমবেড',
	'Setup': 'সেটআপ',
	'Get started': 'শুরু করুন',
	'Upload a PDF': 'একটি PDF আপলোড করুন',
	'Add a file, or drop one on the stage.': 'একটি ফাইল যোগ করুন, অথবা স্টেজে ফেলুন।',
	'Upload': 'আপলোড',
	'Copy a shortcode': 'একটি শর্টকোড কপি করুন',
	'Click the code on the stage to copy.': 'কপি করতে স্টেজের কোডে ক্লিক করুন।',
	'Show stage': 'স্টেজ দেখান',
	'Put it on a page': 'একটি পেজে রাখুন',
	'Publish a test page, or use the viewer block.': 'একটি টেস্ট পেজ প্রকাশ করুন, অথবা ভিউয়ার ব্লক ব্যবহার করুন।',
	'Create page': 'পেজ তৈরি করুন',
	'Embed a document': 'একটি ডকুমেন্ট এমবেড করুন',
	'Drop a PDF, pick a cover, or browse the library.': 'একটি PDF ফেলুন, কভার বেছে নিন, অথবা লাইব্রেরি দেখুন।',
	'No document selected': 'কোনো ডকুমেন্ট বেছে নেওয়া হয়নি',
	'Shortcode': 'শর্টকোড',
	'Click to copy': 'কপি করতে ক্লিক করুন',
	'Copy': 'কপি',
	'Create test page': 'টেস্ট পেজ তৈরি করুন',
	'Browse Media Library': 'মিডিয়া লাইব্রেরি খুলুন',
	'Recent': 'সাম্প্রতিক',
	'Viewer defaults': 'ভিউয়ার ডিফল্ট',
	'Foliora Pro': 'Foliora Pro',
	'Unlocked on this site. Open a tile for details.': 'এই সাইটে আনলক। বিস্তারিত জানতে একটি টালি খুলুন।',
	'Optional add-on. Open a tile to see what it adds.': 'ঐচ্ছিক অ্যাড-অন। কী যোগ করে তা দেখতে একটি টালি খুলুন।',
	'Active': 'সক্রিয়',
	'Locked': 'লকড',
	'Back to Dashboard': 'ড্যাশবোর্ডে ফিরুন',
	'Live preview': 'লাইভ প্রিভিউ',
	'Width and height update this viewer immediately. Save to store them as site defaults.': 'প্রস্থ ও উচ্চতা এই ভিউয়ার তাৎক্ষণিক আপডেট করে। সাইট ডিফল্ট হিসেবে রাখতে সেভ করুন।',
	'Preview uses the most recently uploaded PDF in the Media Library.': 'প্রিভিউতে মিডিয়া লাইব্রেরির সবচেয়ে সম্প্রতি আপলোড করা PDF ব্যবহার হয়।',
	'Open Documents': 'ডকুমেন্টস খুলুন',
	'Upload a PDF to see a live preview of these defaults.': 'এই ডিফল্টগুলোর লাইভ প্রিভিউ দেখতে একটি PDF আপলোড করুন।',
	'Upload PDF': 'PDF আপলোড',
	'PDFs already in the Media Library. Copy a shortcode to embed one with Foliora — files stay in Media, not in a separate library. Opening this screen generates first-page thumbnails, extracts PDF text for WordPress search, and checks whether each file is a tagged (accessible) PDF.': 'মিডিয়া লাইব্রেরিতে যে PDF আছে। Foliora দিয়ে এমবেড করতে শর্টকোড কপি করুন — ফাইল মিডিয়াতেই থাকে, আলাদা লাইব্রেরিতে নয়। এই স্ক্রিন খুললে প্রথম পাতার থাম্বনেইল তৈরি হয়, ওয়ার্ডপ্রেস সার্চের জন্য PDF টেক্সট এক্সট্র্যাক্ট হয়, এবং প্রতিটি ফাইল ট্যাগড (অ্যাকসেসিবল) PDF কি না তা যাচাই হয়।',
	'Generating thumbnails, indexing PDF text, and checking accessibility tags…': 'থাম্বনেইল তৈরি, PDF টেক্সট ইনডেক্স, এবং অ্যাকসেসিবিলিটি ট্যাগ যাচাই চলছে…',
	'Search documents': 'ডকুমেন্ট খুঁজুন',
	'Search': 'সার্চ',
	'%s PDF on this page is not tagged. Screen readers may not follow the intended reading order. Export tagged PDFs from Word, InDesign, or Acrobat.': 'এই পাতার %sটি PDF ট্যাগড নয়। স্ক্রিন রিডার কাঙ্ক্ষিত পড়ার ক্রম অনুসরণ নাও করতে পারে। Word, InDesign বা Acrobat থেকে ট্যাগড PDF এক্সপোর্ট করুন।',
	'%s PDFs on this page are not tagged. Screen readers may not follow the intended reading order. Export tagged PDFs from Word, InDesign, or Acrobat.': 'এই পাতার %sটি PDF ট্যাগড নয়। স্ক্রিন রিডার কাঙ্ক্ষিত পড়ার ক্রম অনুসরণ নাও করতে পারে। Word, InDesign বা Acrobat থেকে ট্যাগড PDF এক্সপোর্ট করুন।',
	'Preview': 'প্রিভিউ',
	'Accessibility': 'অ্যাকসেসিবিলিটি',
	'Size': 'আকার',
	'Uploaded': 'আপলোডের তারিখ',
	'Open file': 'ফাইল খুলুন',
	'Pending': 'অপেক্ষমাণ',
	'%s document': '%sটি ডকুমেন্ট',
	'%s documents': '%sটি ডকুমেন্ট',
	'No PDFs matched that search.': 'সেই সার্চে কোনো PDF মেলেনি।',
	'No PDFs in the Media Library yet.': 'মিডিয়া লাইব্রেরিতে এখনো কোনো PDF নেই।',
	'Select a PDF first.': 'আগে একটি PDF বেছে নিন।',
	'Foliora Test': 'Foliora টেস্ট',
	'Open EPUB files in the same embed, not just PDF.': 'শুধু PDF নয়, একই এমবেডে EPUB ফাইলও খুলুন।',
	'Page-turn animation for a magazine-style read.': 'ম্যাগাজিন-স্টাইল পড়ার জন্য পাতা ওল্টানো অ্যানিমেশন।',
	'Logged-in readers can resume where they left off.': 'লগইন করা পাঠক যেখানে থেমেছিলেন সেখান থেকে চালিয়ে যেতে পারেন।',
	'Light, sepia, and dark themes for long reading.': 'দীর্ঘ পড়ার জন্য লাইট, সেপিয়া ও ডার্ক থিম।',
	'Password, expiry, and view-capped share URLs.': 'পাসওয়ার্ড, মেয়াদ ও ভিউ-সীমাযুক্ত শেয়ার URL।',
	'Limit documents to paying WooCommerce customers.': 'পেইং WooCommerce কাস্টমারদের জন্য ডকুমেন্ট সীমিত করুন।',
	'See which documents get opened and how far.': 'কোন ডকুমেন্ট খোলা হয় এবং কতদূর পড়া হয় তা দেখুন।',
	'Hide “Powered by Foliora” on the public viewer.': 'পাবলিক ভিউয়ার থেকে “Powered by Foliora” লুকান।',
	'PDF': 'PDF',
	'PDF URL': 'PDF URL',
	'Paste a Media Library PDF URL, or copy one from Foliora → Documents.': 'মিডিয়া লাইব্রেরির PDF URL পেস্ট করুন, অথবা Foliora → ডকুমেন্টস থেকে কপি করুন।',
	'Library': 'লাইব্রেরি',
	'Search documents…': 'ডকুমেন্ট খুঁজুন…',
	'Previous': 'আগের',
	'Next': 'পরের',
	'Document library pages': 'ডকুমেন্ট লাইব্রেরির পাতা',
	'No PDFs found.': 'কোনো PDF পাওয়া যায়নি।',
	'EPUB reader mode': 'EPUB রিডার মোড',
	'3D WebGL flip effect': '3D WebGL ফ্লিপ ইফেক্ট',
	'Bookmarks & reading progress': 'বুকমার্ক ও রিডিং প্রগ্রেস',
	'Font & theme customization': 'ফন্ট ও থিম কাস্টমাইজেশন',
	'Password-protected / expiring links': 'পাসওয়ার্ড-সুরক্ষিত / মেয়াদোত্তীর্ণ লিংক',
	'WooCommerce paid-content gating': 'WooCommerce পেইড-কন্টেন্ট গেটিং',
	'Reading analytics': 'রিডিং অ্যানালিটিক্স',
	'Remove Foliora branding': 'Foliora ব্র্যান্ডিং সরান',
	'PDF text indexing is turned off.': 'PDF টেক্সট ইনডেক্সিং বন্ধ আছে।',
	'Invalid thumbnail data.': 'থাম্বনেইল ডেটা অবৈধ।',
	'Thumbnail is too large.': 'থাম্বনেইল খুব বড়।',
	'Foliora thumbnail: %s': 'Foliora থাম্বনেইল: %s',
	'Could not save the thumbnail.': 'থাম্বনেইল সেভ করা যায়নি।',
	'Foliora: no file URL was provided. Use [foliora file="https://example.com/document.pdf"].': 'Foliora: কোনো ফাইল URL দেওয়া হয়নি। ব্যবহার করুন [foliora file="https://example.com/document.pdf"]।',
	'Powered by Foliora': 'Powered by Foliora',
	'PDF viewer': 'PDF ভিউয়ার',
	'Previous page': 'আগের পাতা',
	'Page': 'পাতা',
	'Next page': 'পরের পাতা',
	'Zoom out': 'জুম আউট',
	'Zoom in': 'জুম ইন',
	'Rotate': 'ঘোরান',
	'Find': 'খুঁজুন',
	'Find in document': 'ডকুমেন্টে খুঁজুন',
	'Previous match': 'আগের মিল',
	'Next match': 'পরের মিল',
	'Download': 'ডাউনলোড',
	'Print': 'প্রিন্ট',
	'Full screen': 'ফুল স্ক্রিন',
	'Keyboard: left and right arrows change page, plus and minus zoom, R rotates, Home and End jump to first or last page.': 'কীবোর্ড: বাম ও ডান অ্যারো পাতা বদলায়, প্লাস ও মাইনাস জুম করে, R ঘোরায়, Home ও End প্রথম বা শেষ পাতায় যায়।',
	'is a Foliora Pro feature.': 'একটি Foliora Pro ফিচার।',
	'Upgrade to unlock': 'আনলক করতে আপগ্রেড করুন',
	'Viewer Settings': 'ভিউয়ার সেটিংস',
	'CSS width, for example 100% or 720px.': 'CSS প্রস্থ, যেমন 100% বা 720px।',
	'Height (px)': 'উচ্চতা (px)',
	'Optional caption above the viewer.': 'ভিউয়ারের উপরে ঐচ্ছিক ক্যাপশন।',
	'Show download': 'ডাউনলোড দেখান',
	'Show print': 'প্রিন্ট দেখান',
	'Replace PDF': 'PDF বদলান',
	'Select a PDF to preview the first page here. Visitors will see the full Foliora viewer.': 'এখানে প্রথম পাতার প্রিভিউ দেখতে একটি PDF বেছে নিন। ভিজিটররা পুরো Foliora ভিউয়ার দেখবে।',
	'Library Settings': 'লাইব্রেরি সেটিংস',
	'Documents per page': 'প্রতি পাতায় ডকুমেন্ট',
	'Last modified': 'শেষ পরিবর্তন',
	'Show search': 'সার্চ দেখান',
	'A browsable grid of PDFs from the Media Library.': 'মিডিয়া লাইব্রেরির PDF-এর একটি ব্রাউজযোগ্য গ্রিড।',
	'pdf': 'pdf',
	'library': 'library',
	'gallery': 'gallery',
	'documents': 'documents',
	'Embed a PDF document with the Foliora viewer.': 'Foliora ভিউয়ার দিয়ে একটি PDF ডকুমেন্ট এমবেড করুন।',
	'document': 'document',
	'viewer': 'viewer',
	'ebook': 'ebook',
};

function parseBlocks( src ) {
	const blocks = [];
	const parts = src.split( /\n\n+/ );
	for ( const raw of parts ) {
		if ( ! raw.includes( 'msgid' ) ) {
			continue;
		}
		blocks.push( raw.trim() );
	}
	return blocks;
}

function extractMsgid( block ) {
	const lines = block.split( /\n/ );
	let mode = null;
	let id = '';
	let idPlural = '';
	for ( const line of lines ) {
		if ( line.startsWith( 'msgid_plural ' ) ) {
			mode = 'plural';
			idPlural += JSON.parse( line.slice( 'msgid_plural '.length ) );
			continue;
		}
		if ( line.startsWith( 'msgid ' ) ) {
			mode = 'id';
			id += JSON.parse( line.slice( 'msgid '.length ) );
			continue;
		}
		if ( mode && line.startsWith( '"' ) ) {
			const chunk = JSON.parse( line );
			if ( mode === 'id' ) {
				id += chunk;
			} else if ( mode === 'plural' ) {
				idPlural += chunk;
			}
			continue;
		}
		if ( line.startsWith( 'msgstr' ) ) {
			mode = null;
		}
	}
	return { id, idPlural };
}

const header = `# Copyright (C) 2026 The Read Scope
# This file is distributed under the GPL v2 or later.
msgid ""
msgstr ""
"Project-Id-Version: Foliora 1.7.0\\n"
"Report-Msgid-Bugs-To: https://wordpress.org/support/plugin/foliora\\n"
"POT-Creation-Date: 2026-09-24T04:54:10+00:00\\n"
"PO-Revision-Date: 2026-09-24 10:55+0600\\n"
"Last-Translator: The Read Scope <hello@thereadscope.com>\\n"
"Language-Team: Bengali (Bangladesh)\\n"
"Language: bn_BD\\n"
"MIME-Version: 1.0\\n"
"Content-Type: text/plain; charset=UTF-8\\n"
"Content-Transfer-Encoding: 8bit\\n"
"Plural-Forms: nplurals=2; plural=(n != 1);\\n"
"X-Generator: Foliora\\n"
"X-Domain: foliora\\n"
`;

const potBody = pot.replace( /^[\s\S]*?X-Domain: foliora\\n"\n/, '' );
const blocks = parseBlocks( potBody );
const missing = [];
const out = [ header.trim(), '' ];

for ( const block of blocks ) {
	const { id, idPlural } = extractMsgid( block );
	if ( id === '' ) {
		continue;
	}
	const comments = block
		.split( /\n/ )
		.filter( ( line ) => line.startsWith( '#' ) )
		.join( '\n' );
	const msgidLines = block
		.split( /\n/ )
		.filter( ( line ) => line.startsWith( 'msgid' ) || line.startsWith( 'msgctxt' ) || ( line.startsWith( '"' ) && ! line.startsWith( 'msgstr' ) ) )
		.join( '\n' );

	if ( idPlural ) {
		const s = t[ id ];
		const p = t[ idPlural ];
		if ( ! s || ! p ) {
			missing.push( idPlural || id );
		}
		out.push( comments );
		out.push( msgidLines.replace( /\nmsgstr[\s\S]*/, '' ).trim() );
		out.push( `msgstr[0] ${ JSON.stringify( s || '' ) }` );
		out.push( `msgstr[1] ${ JSON.stringify( p || '' ) }` );
		out.push( '' );
		continue;
	}

	if ( ! Object.prototype.hasOwnProperty.call( t, id ) ) {
		missing.push( id );
	}
	const translated = t[ id ] || '';
	out.push( comments );
	const ctxAndId = [];
	for ( const line of block.split( /\n/ ) ) {
		if ( line.startsWith( 'msgctxt' ) || line.startsWith( 'msgid' ) || ( ctxAndId.length && line.startsWith( '"' ) && ! line.startsWith( 'msgstr' ) ) ) {
			ctxAndId.push( line );
			continue;
		}
		if ( line.startsWith( 'msgstr' ) ) {
			break;
		}
	}
	out.push( ctxAndId.join( '\n' ) );
	out.push( `msgstr ${ JSON.stringify( translated ) }` );
	out.push( '' );
}

if ( missing.length ) {
	console.error( 'Missing translations:', missing );
	process.exit( 1 );
}

fs.writeFileSync( path.join( root, 'languages', 'foliora-bn_BD.po' ), out.join( '\n' ) + '\n', 'utf8' );
console.log( 'Wrote foliora-bn_BD.po', blocks.length, 'entries' );
