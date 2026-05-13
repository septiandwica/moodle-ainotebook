# PresMate AI Notebook Creator Hub

PresMate is a premium AI-powered study assistant plugin for Moodle, developed by **septiandwica** and supported by **Tateta** ([samastanuswantara.com](https://samastanuswantara.com)). This plugin is **exclusive for President University**. It transforms traditional study materials into interactive, AI-driven learning experiences.

![PresMate Icon](pix/icon.svg)

## 🚀 Key Features

### 🧠 Intelligent Study Chat
- **Context-Aware AI**: PresMate "reads" your uploaded study materials and provides answers strictly based on the provided context.
- **Configurable Personalities**: Choose between a *Helpful Assistant*, *Professional Tutor*, or *Critical Thinker* to suit your learning style.
- **Dynamic Suggestions**: Get 2-3 smart follow-up questions after every response to dive deeper into the topic.

### 🎨 Creator Hub (Artifacts)
Generate professional-grade academic materials in seconds:
- **Interactive Quizzes**: 4-option multiple-choice questions with smart hints and instant scoring.
- **Visual Mindmaps**: Automatically generated diagrams using Mermaid.js to visualize complex concepts.
- **Study Reports**: Detailed, structured markdown reports summarizing key takeaways from your materials.

### 📄 Professional PDF Export
- **Clean Layouts**: Export your generated reports and mindmaps into high-quality PDFs.
- **Branded Headers**: Every export includes the President University formal header and student metadata.
- **Isolated Content**: Smart print styles ensure only the academic content is exported, excluding Moodle site navigation.

### 💎 Premium UI/UX
- **Modern Aesthetics**: Built with a sleek glassmorphism design, vibrant color palettes, and smooth micro-animations.
- **Independent Scrolling**: Isolated preview areas for large documents to ensure a stable workspace.
- **Responsive Workspace**: Optimized for various screen sizes, ensuring a consistent experience across devices.

## 🛠 Installation

1. Clone this repository into your Moodle installation:
   ```bash
   git clone https://github.com/septiandwica/moodle-ainotebook.git mod/ainotebook
   ```
2. Log in to your Moodle site as an administrator and go to **Site Administration > Notifications**.
3. Follow the prompts to install the plugin.
4. Configure the AI settings (API keys, provider) in **Site Administration > Plugins > Activity Modules > AI Notebook**.

## ⚙️ Technical Requirements

- **Moodle**: 5.0+ (Tested on Moodle 5.0)
- **Node.js**: 22+ (for development/build tasks)
- **Dependencies**: 
  - [Mermaid.js](https://mermaid.js.org/) for mindmaps.
  - [Marked.js](https://marked.js.org/) for markdown rendering.
  - FontAwesome 4.7 for iconography.

## 🔒 Security & Privacy

- **Toxicity Filter**: Built-in monitoring to ensure professional academic conduct.
- **Context Locking**: The AI is strictly instructed to stick to the provided study materials to prevent hallucinations.
- **Activity Logging**: All interactions are recorded and stored for academic review by the institution.

---
Developed by **septiandwica** & Supported by **Tateta** ([samastanuswantara.com](https://samastanuswantara.com)).
