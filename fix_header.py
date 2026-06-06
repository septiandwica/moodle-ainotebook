import re

with open('templates/view.mustache', 'r') as f:
    text = f.read()

bad_block = """        var prepareFormalHeader = function(title) {
            var now = new Date().toLocaleDateString();
            return '<div class="summary-section">' + 
            <div class="pdf-cover-page">
                <div class="pdf-cover-logo">
                    <img src="${pdfLogoUrl}" class="pdf-brand-logo-large">
                    <div class="pdf-brand-text-large">
                        <h1 class="pdf-univ-title">PRESIDENT</h1>
                        <h1 class="pdf-univ-subtitle">UNIVERSITY</h1>
                    </div>
                </div>
                
                <div class="pdf-cover-main">
                    <h1 class="pdf-report-title">${title.toUpperCase()}:<br/>${activityName.toUpperCase()}</h1>
                </div>

                <div class="pdf-cover-footer">
                    <div class="pdf-info-card">
                        <div class="info-row" style="font-weight: 800;">${studentName}</div>
                    </div>
                </div>
            </div>
            <div class="pdf-page-header">
                <div class="pdf-header-label">${title.toUpperCase()}</div>
'</div>';
        };"""

good_block = """        var prepareFormalHeader = function(title) {
            var now = new Date().toLocaleDateString();
            return '<div class="pdf-cover-page">' +
                '<div class="pdf-cover-logo">' +
                    '<img src="' + pdfLogoUrl + '" class="pdf-brand-logo-large">' +
                    '<div class="pdf-brand-text-large">' +
                        '<h1 class="pdf-univ-title">PRESIDENT</h1>' +
                        '<h1 class="pdf-univ-subtitle">UNIVERSITY</h1>' +
                    '</div>' +
                '</div>' +
                '<div class="pdf-cover-main">' +
                    '<h1 class="pdf-report-title">' + (title ? title.toUpperCase() : '') + ':<br/>' + (activityName ? activityName.toUpperCase() : '') + '</h1>' +
                '</div>' +
                '<div class="pdf-cover-footer">' +
                    '<div class="pdf-info-card">' +
                        '<div class="info-row" style="font-weight: 800;">' + studentName + '</div>' +
                    '</div>' +
                '</div>' +
            '</div>' +
            '<div class="pdf-page-header">' +
                '<div class="pdf-header-label">' + (title ? title.toUpperCase() : '') + '</div>' +
            '</div>';
        };"""

text = text.replace(bad_block, good_block)

with open('templates/view.mustache', 'w') as f:
    f.write(text)
print("Fixed prepareFormalHeader syntax!")
