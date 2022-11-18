from flask import Flask, request, render_template, redirect, url_for, abort, flash, session, g


app = Flask(__name__)


@app.route('/')
def show_accueil():
    return render_template('layout.html')

def show_page1():
    return render_template('articles/page1.html')








if __name__ == '__main__':
    app.run()
